<?php

namespace App\Services\System;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * يسحب الكود من GitHub ويُكمل ما يلزمه: الترحيلات، والمكتبات إن تغيّرت،
 * وبناء الواجهة إن تغيّرت.
 *
 * نسخة GitHub هي المرجع: ما عُدِّل على الخادم مباشرةً يُطرح لصالحها، ويُقال
 * ذلك في السجل وفي الفحص قبل التحديث. والرمز لا يُكتب في أي سجلّ.
 */
class SystemUpdater
{
    private ?string $token = null;

    public function __construct(
        private ?string $owner = null,
        private ?string $repo = null,
        private ?string $branch = null,
    ) {
        $this->owner ??= (string) config('deploy.owner');
        $this->repo ??= (string) config('deploy.repo');
        $this->branch ??= (string) config('deploy.branch', 'main');
        $this->token = config('deploy.token');
    }

    /** مستودعٌ ورمزٌ غير ما في الإعداد — لنداء النشر الخارجي. */
    public static function using(string $token, string $repo, ?string $branch = null, ?string $owner = null): self
    {
        $updater = new self($owner, $repo, $branch);
        $updater->token = $token;

        return $updater;
    }

    public function configured(): bool
    {
        return filled($this->owner) && filled($this->repo) && filled($this->branch) && filled($this->token);
    }

    public function isGitRepository(): bool
    {
        return $this->run('git rev-parse --is-inside-work-tree')['ok'];
    }

    /**
     * النسخة العاملة الآن.
     *
     * @return array{commit: string, message: string, date: string}|null
     */
    public function current(): ?array
    {
        $result = $this->run('git log -1 --format=%h%x1f%s%x1f%ci');

        if (! $result['ok'] || trim($result['output']) === '') {
            return null;
        }

        [$commit, $message, $date] = array_pad(explode("\x1f", trim($result['output'])), 3, '');

        return compact('commit', 'message', 'date');
    }

    /**
     * ما في GitHub ولم يصل بعد، وما عُدِّل هنا وسيُطرح.
     *
     * @return array{ok: bool, error?: string, behind?: int, commits?: list<string>, dirty?: list<string>}
     */
    public function check(): array
    {
        $fetch = $this->fetch();

        if (! $fetch['ok']) {
            return ['ok' => false, 'error' => $this->mask($fetch['error'] ?: $fetch['output'])];
        }

        $log = $this->run('git log --format=%h%x20%s -30 HEAD..FETCH_HEAD');

        return [
            'ok' => true,
            'behind' => (int) trim($this->run('git rev-list --count HEAD..FETCH_HEAD')['output']),
            'commits' => $this->lines($log['output']),
            'dirty' => $this->dirty(),
        ];
    }

    /**
     * @return array{ok: bool, log: string, from: ?string, to: ?string}
     */
    public function update(): array
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $from = $this->current()['commit'] ?? null;
        $log = [];

        if ($dirty = $this->dirty()) {
            $log[] = "تعديلات محلية طُرحت لصالح نسخة GitHub:\n".implode("\n", $dirty);
        }

        $fetch = $this->fetch();
        $log[] = '$ git fetch '.$this->owner.'/'.$this->repo.' '.$this->branch."\n".$this->mask($fetch['output'].$fetch['error']);

        if (! $fetch['ok']) {
            return $this->finish(false, $log, $from);
        }

        $changed = $this->lines($this->run('git diff --name-only HEAD FETCH_HEAD')['output']);

        foreach (['git reset --hard FETCH_HEAD', 'php artisan optimize:clear', 'php artisan migrate --force'] as $step) {
            $result = $this->run($step);
            $log[] = '$ '.$step."\n".trim($result['output']."\n".$result['error']);

            if (! $result['ok']) {
                return $this->finish(false, $log, $from);
            }
        }

        if ($this->touches($changed, ['composer.json', 'composer.lock'])) {
            $log[] = $this->optional('composer', 'composer install --no-dev --no-interaction --optimize-autoloader');
        }

        if ($this->touches($changed, ['package.json', 'package-lock.json', 'vite.config', 'resources/js/', 'resources/css/', 'tailwind.config'])) {
            $log[] = $this->buildAssets();
        } else {
            $log[] = 'الواجهة لم تتغيّر — لا حاجة لإعادة البناء.';
        }

        // المهام في الطابور تحمل الكود القديم حتى يُعاد تشغيل عمّالها.
        $this->run('php artisan queue:restart');

        return $this->finish(true, $log, $from);
    }

    /** @return array{at: string, ok: bool, from: ?string, to: ?string, log: string}|null */
    public function lastRun(): ?array
    {
        $path = $this->historyPath();

        return File::exists($path) ? json_decode(File::get($path), true) : null;
    }

    private function buildAssets(): string
    {
        if (! $this->available('npm')) {
            return 'npm غير متوفر على الخادم — شغّل npm ci && npm run build يدوياً لتحديث public/build.';
        }

        foreach (['npm ci', 'npm run build'] as $step) {
            $result = $this->run($step);

            if (! $result['ok']) {
                return "فشل {$step} — بقيت ملفات الواجهة السابقة:\n".trim($result['error'] ?: $result['output']);
            }
        }

        return 'تم بناء الواجهة (public/build).';
    }

    private function optional(string $binary, string $command): string
    {
        if (! $this->available($binary)) {
            return "{$binary} غير متوفر على الخادم — شغّل «{$command}» يدوياً.";
        }

        $result = $this->run($command);

        return '$ '.$command."\n".trim($result['output']."\n".$result['error']);
    }

    /** @return array{ok: bool, output: string, error: string} */
    private function fetch(): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'output' => '', 'error' => 'بيانات المستودع أو رمز GitHub ناقصة.'];
        }

        $url = 'https://'.$this->token.'@github.com/'.$this->owner.'/'.$this->repo.'.git';

        return $this->run('git fetch '.escapeshellarg($url).' '.escapeshellarg($this->branch));
    }

    /** @return list<string> */
    private function dirty(): array
    {
        return $this->lines($this->run('git status --porcelain --untracked-files=no')['output']);
    }

    private function touches(array $changed, array $prefixes): bool
    {
        foreach ($changed as $file) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($file, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function available(string $binary): bool
    {
        return $this->run(PHP_OS_FAMILY === 'Windows' ? "where {$binary}" : "command -v {$binary}")['ok'];
    }

    /** @return array{ok: bool, log: string, from: ?string, to: ?string} */
    private function finish(bool $ok, array $log, ?string $from): array
    {
        $run = [
            'at' => now()->toDateTimeString(),
            'ok' => $ok,
            'from' => $from,
            'to' => $this->current()['commit'] ?? null,
            'log' => $this->mask(implode("\n\n", array_filter($log))),
        ];

        rescue(fn () => File::put($this->historyPath(), json_encode($run, JSON_UNESCAPED_UNICODE)), null, false);

        return $run;
    }

    private function historyPath(): string
    {
        return storage_path('app/system-update.json');
    }

    /** @return array{ok: bool, output: string, error: string} */
    private function run(string $command): array
    {
        // رمزٌ خاطئ يجعل git يسأل عن كلمة مرور لا يراها أحد، فيعلق الطلب حتى المهلة.
        $process = Process::fromShellCommandline($command, base_path(), [
            'GIT_TERMINAL_PROMPT' => '0',
            'GCM_INTERACTIVE' => 'never',
        ]);
        $process->setTimeout((int) config('deploy.timeout', 600));

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => $e->getMessage()];
        }

        return [
            'ok' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    private function mask(string $text): string
    {
        return filled($this->token) ? str_replace($this->token, '***', $text) : $text;
    }

    /** @return list<string> */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $output)), fn ($l) => $l !== ''));
    }
}
