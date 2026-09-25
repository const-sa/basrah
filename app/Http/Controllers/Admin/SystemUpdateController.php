<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\System\SystemUpdater;
use App\Services\Whatsapp\EnvFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * تحديث النظام من GitHub من داخل لوحة التحكم: بيانات المستودع تُكتب في .env،
 * والفحص يقول ما الجديد قبل السحب، والتحديث يسحب ويرحّل ويبني.
 */
class SystemUpdateController extends Controller
{
    public function edit(SystemUpdater $updater): Response
    {
        $token = (string) config('deploy.token');

        return Inertia::render('admin/settings/SystemUpdate', [
            'repository' => [
                'owner' => (string) config('deploy.owner'),
                'repo' => (string) config('deploy.repo'),
                'branch' => (string) config('deploy.branch'),
                // بصمة الرمز لا الرمز: ما يكفي للتعرّف لا للاستعمال.
                'saved_token' => $token === '' ? '' : substr($token, 0, 4).str_repeat('•', 6).substr($token, -4),
            ],
            'configured' => $updater->configured(),
            'is_git' => $updater->isGitRepository(),
            'current' => $updater->current(),
            'last_run' => $updater->lastRun(),
            'env_writable' => EnvFile::make()->writable(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'owner' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'repo' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'branch' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\/-]+$/'],
            // فارغ = إبقاء الرمز المحفوظ.
            'token' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_]+$/'],
        ], [
            'owner.regex' => 'اسم المالك: حروف إنجليزية وأرقام و - _ . فقط.',
            'repo.regex' => 'اسم المستودع: حروف إنجليزية وأرقام و - _ . فقط.',
            'branch.regex' => 'اسم الفرع غير صالح.',
            'token.regex' => 'رمز GitHub غير صالح.',
        ]);

        $env = EnvFile::make();

        if (! $env->writable()) {
            return back()->with('warning', 'ملف .env غير قابل للكتابة على الخادم — تعذّر حفظ بيانات المستودع.');
        }

        $keys = (array) config('deploy.env_keys');
        $values = [
            $keys['owner'] => $data['owner'],
            $keys['repo'] => $data['repo'],
            $keys['branch'] => $data['branch'],
        ];

        if (filled($data['token'] ?? null)) {
            $values[$keys['token']] = trim($data['token']);
        }

        $env->set($values);

        // env() لا يُقرأ إلا عند تحميل الإعداد، فيُنسخ ما كُتب إلى الإعداد الحيّ.
        config(collect($values)->mapWithKeys(fn ($value, $envKey) => [
            'deploy.'.array_search($envKey, $keys, true) => $value,
        ])->all());

        if (app()->configurationIsCached()) {
            rescue(fn () => Artisan::call('config:clear'), null, false);
        }

        return back()->with('success', 'تم حفظ بيانات المستودع.');
    }

    /** ما الجديد في GitHub، دون تغيير شيء. */
    public function check(SystemUpdater $updater): JsonResponse
    {
        return response()->json($updater->check());
    }

    public function run(SystemUpdater $updater): JsonResponse
    {
        if (! $updater->configured()) {
            return response()->json(['ok' => false, 'log' => 'أكمل بيانات المستودع ورمز GitHub واحفظها أولاً.']);
        }

        $result = $updater->update();

        return response()->json($result + ['current' => $updater->current()]);
    }
}
