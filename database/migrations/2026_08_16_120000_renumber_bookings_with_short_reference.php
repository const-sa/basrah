<?php

use App\Models\Booking;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * إعادة ترقيم الحجوزات إلى الصيغة القصيرة a-1 المتسلسلة من 1.
 *
 * الرقم الطويل BK-2026-0001 كان يُقرأ على الهاتف حرفًا حرفًا، ورقم الحجز
 * يُملى على العميل ويُكتب على العقد والسند، فقُصِّر إلى ما يُقال في نفَس.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = Booking::REFERENCE_PREFIX;

        $bookings = DB::table('bookings')->orderBy('id')->get(['id', 'reference']);

        if ($bookings->isEmpty()) {
            return;
        }

        // الترقيم على مرحلتين: العمود فريد، وإسناد a-2 لصفٍّ بينما يحمله
        // صفٌّ آخر لم يُنقل بعد يفشل. فتُنقل الأرقام أولًا إلى صيغة مؤقتة
        // لا تشبه شيئًا، ثم تُثبَّت. وإلا انكسر الترحيل على قاعدة قائمة.
        DB::transaction(function () use ($bookings, $prefix) {
            foreach ($bookings as $booking) {
                DB::table('bookings')
                    ->where('id', $booking->id)
                    ->update(['reference' => '__tmp_ref_'.$booking->id]);
            }

            foreach ($bookings->values() as $i => $booking) {
                $reference = $prefix.($i + 1);

                DB::table('bookings')->where('id', $booking->id)->update(['reference' => $reference]);

                // نص العقد مجمَّد وقت توليده وفيه رقم الحجز القديم: تُصحَّح
                // الإشارة وحدها فيبقى العقد على صياغته ويشير إلى رقم موجود.
                $this->syncContracts($booking->reference, $reference);
            }
        });
    }

    /**
     * الترحيل العكسي لا يستعيد الأرقام القديمة — لم تُحفظ في مكان آخر،
     * ولا سبيل لاشتقاق سنتها من الرقم القصير. فيُترك الترقيم كما استقرّ.
     */
    public function down(): void {}

    private function syncContracts(string $old, string $new): void
    {
        if ($old === '' || $old === $new) {
            return;
        }

        $contracts = DB::table('contracts')
            ->where('body', 'like', '%'.$old.'%')
            ->orWhere('terms', 'like', '%'.$old.'%')
            ->orWhere('data', 'like', '%'.$old.'%')
            ->get(['id', 'body', 'terms', 'data']);

        foreach ($contracts as $contract) {
            DB::table('contracts')->where('id', $contract->id)->update([
                'body' => str_replace($old, $new, (string) $contract->body),
                'terms' => $contract->terms === null ? null : str_replace($old, $new, (string) $contract->terms),
                'data' => $contract->data === null ? null : str_replace($old, $new, (string) $contract->data),
            ]);
        }
    }
};
