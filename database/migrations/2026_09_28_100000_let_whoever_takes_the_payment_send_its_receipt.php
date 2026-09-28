<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * من يقبض الدفعة يرسل سندها.
 *
 * السند يُرسل من شاشة الحجز لا من شاشة الواتساب، فمشرف الوحدة الذي سجّل
 * الدفعة هو صاحب الرسالة. لكن أدواراً أُنشئت قبل أن تُضاف whatsapp.send إلى
 * «مشرف وحدة» بقيت بلا المفتاح، فالزرّ يغيب عنه ويظهر للمالك وحده.
 *
 * تُمنح هنا لكل دورٍ يعدّل الحجوزات أصلاً — فهو يقبض الدفعات — ولا يُمسّ دورٌ
 * لا يعدّلها. والمنح إضافةٌ لا استبدال: ما ضبطه المستخدم بيده يبقى كما ضبطه.
 */
return new class extends Migration
{
    /** من يعدّل حجزاً فهو من يقبض دفعته. */
    private const BOOKING_EDITORS = ['hall_bookings.edit', 'chalet_bookings.edit'];

    private const GRANTED = ['whatsapp.view', 'whatsapp.send'];

    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions) || ! array_intersect(self::BOOKING_EDITORS, $permissions)) {
                continue;
            }

            $missing = array_diff(self::GRANTED, $permissions);

            if ($missing === []) {
                continue;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_merge($permissions, $missing)), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * لا تُنزع: النزع يأخذ المفتاح ممن مُنح إياه قصداً قبل هذه الهجرة، ولا
     * سبيل للتمييز بينهما. وصلاحيةٌ زائدة تُسحب من شاشة الأدوار في ثانية.
     */
    public function down(): void {}
};
