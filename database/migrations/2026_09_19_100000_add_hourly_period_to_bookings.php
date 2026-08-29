<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * شكلٌ رابع للحجز: بالساعات.
 *
 * كان الشاليه يُباع بالليلة أو بفترةٍ من فترات اليوم الثلاث، وساعاتُ الفترات
 * مكتوبة سلفًا. والعميل يطلب أحيانًا من الرابعة إلى التاسعة بمبلغٍ يُتَّفق
 * عليه في المكالمة، فلا فترةَ تسعه ولا سعرَ في الجدول يطابقه. فأُضيفت قيمة
 * 'hourly' إلى عمود period: ساعتاه يكتبهما الموظف في المدى نفسه
 * (starts_at → ends_at)، ومبلغه يُدخَل يدويًا.
 *
 * ولا عمود جديد لعدد الساعات: هو الفرق بين طرفَي المدى، والمدى هو ما يُكشف
 * به التعارض. وعمودٌ يُخزَّن بجواره يفتح بابًا لأن يقول الحجز خمس ساعات
 * ويقول مداه أربعًا.
 */
return new class extends Migration
{
    private const OLD = ['morning', 'evening', 'full_day', 'overnight'];

    private const NEW = ['morning', 'evening', 'full_day', 'overnight', 'hourly'];

    public function up(): void
    {
        $this->redefine(self::NEW);
    }

    public function down(): void
    {
        // الحجوزات بالساعات تصير فتراتٍ كاملة اليوم: مداها محفوظ كما هو،
        // فيبقى التعارض مكشوفًا وإن ضاع اسم شكلها.
        DB::table('bookings')->where('period', 'hourly')->update(['period' => 'full_day']);

        $this->redefine(self::OLD);
    }

    /**
     * إعادة تعريف العمود نصًّا — على غرار ما جرى في هجرة حالات الحجز:
     * doctrine/dbal لا يفهم أعمدة enum في MySQL، وSQLite لا يعرف MODIFY ولا
     * ENUM أصلًا فيقبل أي نص، ويجري التحقق في طبقة التطبيق (Rule::in).
     *
     * @param  list<string>  $periods
     */
    private function redefine(array $periods): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = collect($periods)->map(fn (string $p) => "'{$p}'")->implode(', ');

        DB::statement("ALTER TABLE `bookings` MODIFY `period`
            ENUM({$values})
            NOT NULL
            COMMENT 'صباحي | مسائي | يوم كامل | مبيت بالليلة | بالساعات'");
    }
};
