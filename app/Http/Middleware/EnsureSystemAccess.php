<?php

namespace App\Http\Middleware;

use Closure;
use App\Support\SystemRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يحمي نظامًا كاملاً دفعة واحدة بدل تكرار الصلاحيات على كل مسار.
 * الاستخدام: ->middleware('system:accounting')
 *
 * يمرّ المستخدم إن ملك أي صلاحية داخل النظام، ثم تتولى صلاحية
 * الإجراء (perm:journal.create) الفصل الدقيق داخل النظام نفسه.
 */
class EnsureSystemAccess
{
    public function handle(Request $request, Closure $next, string $system): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasSystemAccess($system)) {
            $label = SystemRegistry::SYSTEMS[$system]['label'] ?? $system;

            abort(403, "ليس لديك صلاحية الوصول إلى {$label}.");
        }

        return $next($request);
    }
}
