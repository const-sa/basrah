<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مواءمة حالات الحجز مع الحالات السبع في العرض المعتمد.
 *
 * كان الجدول يعرف خمس حالات لا تطابق ما اتُّفق عليه: «مكتمل» مكان «تم
 * الخروج»، و«لم يحضر» زائدة، وثلاث حالات ناقصة (بانتظار العربون، تم الدخول،
 * مؤجل). والموظف يقرأ الشاشة بالمسميات المتعاقد عليها لا بمرادفاتها.
 *
 * «متاح» الواردة في العرض تبقى خارج هذا العمود: هي غياب الحجز، يعرضها
 * التقويم للوحدة الخالية.
 *
 * تحويل البيانات: «مكتمل» ← «تم الخروج» (نفس المعنى ونفس الأثر المحاسبي)،
 * و«لم يحضر» ← «ملغي» لأنها أقرب حالة باقية إلى عدم إتمام الحجز.
 */
return new class extends Migration
{
    /**
     * تسلسل الحالات كما في العرض — الترتيب يظهر في قوائم الاختيار.
     */
    private const NEW_STATUSES = [
        'tentative',
        'pending_deposit',
        'confirmed',
        'checked_in',
        'checked_out',
        'postponed',
        'cancelled',
    ];

    private const OLD_STATUSES = ['tentative', 'confirmed', 'completed', 'cancelled', 'no_show'];

    public function up(): void
    {
        // توسيع الـ enum قبل النقل، وإلا رُفضت القيمة الجديدة على MySQL.
        $this->redefine([...self::OLD_STATUSES, ...self::NEW_STATUSES], 'confirmed');

        DB::table('bookings')->where('status', 'completed')->update(['status' => 'checked_out']);
        DB::table('bookings')->where('status', 'no_show')->update(['status' => 'cancelled']);

        $this->redefine(self::NEW_STATUSES, 'confirmed');
    }

    public function down(): void
    {
        $this->redefine([...self::OLD_STATUSES, ...self::NEW_STATUSES], 'confirmed');

        DB::table('bookings')->where('status', 'checked_out')->update(['status' => 'completed']);

        // لا مقابل قديمًا للحالات الثلاث المستحدثة: ما كان يحجز التاريخ يعود
        // مبدئيًا، والمؤجل يعود ملغيًا — أقرب ما يحفظ أثره في الجدول القديم.
        DB::table('bookings')->whereIn('status', ['pending_deposit', 'checked_in'])->update(['status' => 'tentative']);
        DB::table('bookings')->where('status', 'postponed')->update(['status' => 'cancelled']);

        $this->redefine(self::OLD_STATUSES, 'confirmed');
    }

    /**
     * إعادة تعريف العمود نصًّا — doctrine/dbal لا يفهم أعمدة enum في MySQL.
     *
     * والعبارة MySQL خالصة: SQLite لا يعرف MODIFY ولا ENUM، فيقبل أي نص في
     * العمود أصلًا. تُتخطّى عليه بلا خسارة — التحقق من الحالة يجري في طبقة
     * التطبيق (Rule::in على Booking::STATUSES)، والاختبارات تعمل على SQLite.
     *
     * @param  list<string>  $statuses
     */
    private function redefine(array $statuses, string $default): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $values = collect($statuses)->unique()->map(fn ($s) => "'{$s}'")->implode(', ');

        DB::statement("ALTER TABLE `bookings` MODIFY `status`
            ENUM({$values})
            NOT NULL DEFAULT '{$default}'
            COMMENT 'مبدئي ← بانتظار العربون ← مؤكد ← تم الدخول ← تم الخروج | مؤجل | ملغي'");
    }
};
