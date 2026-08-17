<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يفرض التحقق من صلاحية محددة على المسار (نظام الأدوار RBAC).
 * الاستخدام في المسارات: ->middleware('perm:clients.create')
 *
 * المدير العام يتجاوز كل الفحوصات (عبر User::hasPermission)،
 * والمستخدم غير المفعّل لا يملك أي صلاحية.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            abort(403, 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        }

        return $next($request);
    }
}
