<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\System\SystemUpdater;
use Illuminate\Http\Request;

/**
 * نداء النشر من خارج النظام. الخطوات نفسها التي ينفّذها زرّ «تحديث النظام»
 * في الإعدادات، غير أن الرمز والمستودع يأتيان في الترويسة.
 */
class DeployController extends Controller
{
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

        $result = SystemUpdater::using(
            $request->header('X-GITHUB-TOKEN'),
            $request->header('X-REPO-NAME'),
            $request->input('branch', 'main'),
            'const-sa',
        )->update();

        return $result['ok']
            ? response()->json(['output' => $result['log']])
            : response()->json(['error' => $result['log']], 400);
    }
}
