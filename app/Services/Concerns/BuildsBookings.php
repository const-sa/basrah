<?php

namespace App\Services\Concerns;

use App\Models\Booking;

/**
 * ما تشترك فيه خدمتا حجز القاعة وحجز الشاليه.
 *
 * الفصل بين النوعين يخصّ طريقة تحديد الوقت والسعر لا طريقة الحفظ: ترقيم
 * الحجوزات وربط الأقسام والخدمات واحد في الحالتين، ونسخه مرتين يضمن أن
 * يُصلَح عيبٌ في نسخة ويبقى في الأخرى.
 */
trait BuildsBookings
{
    /**
     * رقم حجز فريد بصيغة a-1، متسلسل بلا تصفير.
     *
     * الترقيم موحّد بين القاعات والشاليهات عمدًا: رقم الحجز يظهر في العقد
     * وسند القبض والقيد المحاسبي، وتفريعه إلى سلسلتين يجعل رقمين متطابقين
     * يشيران إلى حجزين مختلفين.
     *
     * ولا يُصفَّر مع السنة: العمود فريد، وتصفيره سنويًا يعيد إصدار أرقام
     * سبق أن خرجت على عقود العام الماضي ما لم تُحشر السنة في الرقم.
     */
    protected function nextReference(): string
    {
        $prefix = Booking::REFERENCE_PREFIX;

        // أعلى رقم صدر لا آخر صف أُنشئ: المحذوف ناعمًا يبقى رقمه محجوزًا،
        // فإعادة إصداره تصطدم بقيد التفرّد. والترتيب بالطول ثم بالنص يوافق
        // الترتيب العددي هنا، ويعمل على MySQL وSQLite معًا بخلاف CAST.
        $last = Booking::withTrashed()
            ->where('reference', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(reference) DESC')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.$next;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function syncSections(Booking $booking, string $scope, array $lines): void
    {
        if ($scope !== 'sections') {
            $booking->sections()->detach();

            return;
        }

        $payload = [];
        foreach ($lines as $line) {
            if (isset($line['section_id'])) {
                $payload[$line['section_id']] = ['price' => $line['amount']];
            }
        }

        $booking->sections()->sync($payload);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function syncAddons(Booking $booking, array $lines): void
    {
        $payload = [];
        foreach ($lines as $line) {
            if (isset($line['addon_id'])) {
                $payload[$line['addon_id']] = [
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'total' => $line['amount'],
                ];
            }
        }

        $booking->addons()->sync($payload);
    }
}
