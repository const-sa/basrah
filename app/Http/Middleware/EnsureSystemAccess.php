<?php

namespace App\Http\Middleware;

use App\Support\SystemRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يحمي قسمًا كاملاً دفعة واحدة بدل تكرار الصلاحيات على كل مسار.
 * الاستخدام: ->middleware('system:accounting')
 *
 * وتقبل أقسامًا مفصولة بـ «|» يكفي الوصول إلى أحدها:
 *   ->middleware('system:halls|chalets')
 * لأن ما بعد الحجز (الدفعات والسندات والفواتير) مسارٌ واحد يخدم النشاطين.
 *
 * يمرّ المستخدم إن ملك أي صلاحية داخل القسم، ثم تتولى صلاحية
 * الإجراء (perm:hall_bookings.create) الفصل الدقيق داخل القسم نفسه.
 */
class EnsureSystemAccess
{
    public function handle(Request $request, Closure $next, string $system): Response
    {
        $user = $request->user();
        $sections = explode('|', $system);

        $granted = $user !== null && collect($sections)->contains(fn (string $key) => $user->hasSystemAccess($key));

        if (! $granted) {
            $label = collect($sections)
                ->map(fn (string $key) => SystemRegistry::SYSTEMS[$key]['label'] ?? $key)
                ->join(' أو ');

            abort(403, "ليس لديك صلاحية الوصول إلى {$label}.");
        }

        return $next($request);
    }
}
