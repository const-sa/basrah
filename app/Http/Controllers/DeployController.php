<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;

/**
 * نقطة النشر التلقائي — يستدعيها زر «تحديث» في لوحة update-hosts عن بُعد.
 *
 * الطلب: POST /api/deploy بالترويسات:
 *   X-DEPLOY-TOKEN   (يطابق config('app.deploy_token'))
 *   X-GITHUB-TOKEN   توكن الحساب لسحب المستودع
 *   X-GITHUB-ACCOUNT const-tech | const-sa   (افتراضي: const-tech)
 *   X-REPO-NAME      اسم المستودع
 *
 * تسحب آخر نسخة من GitHub وتشغّل الترحيلات. أصول public/build مُتتبَّعة في git
 * فتصل مع السحب، لذا لا حاجة لتشغيل npm على السيرفر.
 * المرجع العام: e:\www\دليل-النشر-update-hosts.md
 */
class DeployController extends Controller
{
    public function deploy(Request $request)
    {
        if ($request->header('X-DEPLOY-TOKEN') !== config('app.deploy_token')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        if (! $request->header('X-GITHUB-TOKEN')) {
            return response()->json(['error' => 'GITHUB-TOKEN is required'], 400);
        }
        if (! $request->header('X-REPO-NAME')) {
            return response()->json(['error' => 'repo name is required'], 400);
        }

        $token   = $request->header('X-GITHUB-TOKEN');
        $account = $request->header('X-GITHUB-ACCOUNT') ?: 'const-tech';
        $repo    = $request->header('X-REPO-NAME');

        // fetch + reset --hard أكثر أماناً من git pull: يتجاوز أي اختلاف محلي
        // (مثل أصول public/build المُتتبَّعة) دون فشل بسبب تعارض الدمج.
        $command = sprintf(
            'cd %s && git fetch https://%s@github.com/%s/%s.git && git reset --hard FETCH_HEAD && php artisan optimize:clear && php artisan migrate --force',
            escapeshellarg(base_path()),
            $token,
            $account,
            $repo
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            return response()->json(['error' => $process->getErrorOutput() ?: $process->getOutput()], 400);
        }

        return response()->json(['output' => $process->getOutput()]);
    }
}
