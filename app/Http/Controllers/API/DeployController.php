<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

class DeployController extends Controller
{
    private const TIMEOUT = 600;

    public function deploy(Request $request)
    {
        if ($request->header('X-DEPLOY-TOKEN') !== 'f83f3c7f8993adf726d7771f43be317266c30897') {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if (! $request->header('X-GITHUB-TOKEN')) {
            return response()->json(['error' => 'GITHUB-TOKEN is required'], 400);
        }
        if (! $request->header('X-REPO-NAME')) {
            return response()->json(['error' => 'repo name is required'], 400);
        }

        $url = 'https://'.$request->header('X-GITHUB-TOKEN')
            .'@github.com/const-tech/'.$request->header('X-REPO-NAME').'.git';
        $branch = $request->input('branch', 'main');

        $log = [];

        $dirty = trim($this->run('git status --porcelain --untracked-files=no')['output']);
        if ($dirty !== '') {
            $log[] = "Local changes dropped in favor of GitHub version:\n".$dirty;
        }

        $steps = [
            'git fetch '.escapeshellarg($url).' '.escapeshellarg($branch),
            'git reset --hard FETCH_HEAD',
            'php artisan optimize:clear',
            'php artisan migrate --force',
        ];

        foreach ($steps as $step) {
            $result = $this->run($step);
            $log[] = $this->mask($step, $request->header('X-GITHUB-TOKEN'))."\n".$result['output'];

            if (! $result['ok']) {
                return response()->json([
                    'error' => implode("\n\n", $log)."\n".$result['error'],
                ], 400);
            }
        }

        $log[] = $this->buildAssets();

        return response()->json(['output' => implode("\n\n", $log)]);
    }

    private function buildAssets(): string
    {
        if (! $this->run('command -v npm')['ok']) {
            return 'npm is not available on the server — run npm ci && npm run build manually to update public/build.';
        }

        foreach (['npm ci', 'npm run build'] as $step) {
            $result = $this->run($step);

            if (! $result['ok']) {
                return "$step failed — old assets remain:\n".$result['error'];
            }
        }

        return 'Frontend assets (public/build) have been built.';
    }

    /**
     * @return array{ok: bool, output: string, error: string}
     */
    private function run(string $command): array
    {
        $process = Process::fromShellCommandline($command, base_path());
        $process->setTimeout(self::TIMEOUT);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    private function mask(string $command, ?string $token): string
    {
        return $token ? str_replace($token, '***', $command) : $command;
    }
}
