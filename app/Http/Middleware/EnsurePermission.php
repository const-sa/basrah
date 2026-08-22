<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يفرض التحقق من صلاحية محددة على المسار (نظام الأدوار RBAC).
 * الاستخدام في المسارات: ->middleware('perm:clients.create')
 *
 * وتقبل بدائل مفصولة بـ «|» يكفي واحدٌ منها:
 *   ->middleware('perm:halls.edit|chalets.edit')
 * وهذا لمسارٍ يخدم نشاطين — كحذف حجزٍ لا يُعرف نوعه إلا بعد جلبه — على أن
 * يُتمّ المتحكّم الفحص الدقيق بنوع السجل عبر Booking/Unit ‏(TypeScopedPermission).
 *
 * المدير العام يتجاوز كل الفحوصات (عبر User::hasPermission)،
 * والمستخدم غير المفعّل لا يملك أي صلاحية.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $alternatives = explode('|', $permission);

        $granted = $user !== null && collect($alternatives)->contains(fn (string $key) => $user->hasPermission($key));

        if (! $granted) {
            abort(403, 'ليس لديك صلاحية لتنفيذ هذا الإجراء.');
        }

        return $next($request);
    }
}
