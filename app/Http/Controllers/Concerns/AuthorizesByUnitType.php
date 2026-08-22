<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Booking;
use App\Models\Unit;
use Illuminate\Http\Request;

/**
 * الفصل الدقيق بين نشاطَي القاعات والشاليهات في المسارات المشتركة.
 *
 * بعض المسارات تخدم النشاطين بلا تمييزٍ في الرابط: حذف حجز، تسجيل دفعة،
 * تعديل وحدة. النوع لا يُعرف فيها إلا بعد جلب السجل، فوسيط المسار يكتفي
 * بالبديلين (perm:hall_bookings.delete|chalet_bookings.delete) ثم يُتمّ
 * المتحكّم الفحص هنا بالمفتاح الصحيح وحده — وإلا كفت صلاحيةُ القاعات
 * لحذف حجز شاليه.
 */
trait AuthorizesByUnitType
{
    /**
     * صلاحية إجراء على حجز بحسب نوع وحدته (hall_bookings.* أو chalet_bookings.*).
     */
    protected function authorizeBookingAction(Request $request, Booking $booking, string $action): void
    {
        $this->authorizeUnitTypeAction($request, $booking->unit?->type, $action, 'bookings');
    }

    /**
     * صلاحية إجراء على وحدة بحسب نوعها (halls.* أو chalets.*).
     */
    protected function authorizeUnitAction(Request $request, Unit|string|null $unit, string $action): void
    {
        $type = $unit instanceof Unit ? $unit->type : $unit;

        $this->authorizeUnitTypeAction($request, $type, $action);
    }

    /**
     * @param  string|null  $type  hall أو chalet
     * @param  string|null  $suffix  لاحقة اسم الشاشة، فتصير hall_bookings بدل halls
     */
    private function authorizeUnitTypeAction(Request $request, ?string $type, string $action, ?string $suffix = null): void
    {
        $module = $suffix
            ? ($type === 'chalet' ? 'chalet_' : 'hall_').$suffix
            : ($type === 'chalet' ? 'chalets' : 'halls');

        abort_unless(
            (bool) $request->user()?->hasPermission("{$module}.{$action}"),
            403,
            'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
        );
    }
}
