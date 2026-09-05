<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * اختصار حالات الحجز إلى أربع حالات سداد.
 *
 * الحالات السبع كانت تصف مسارًا لا يمشيه أحد: «تم الدخول» و«تم الخروج»
 * خطوتان يضغطهما الموظف على كل حجز، و«مبدئي» و«بانتظار العربون» و«مؤكد»
 * ثلاث تسميات لحجزٍ واحد لم يكتمل مبلغه. وما يُسأل عنه فعلًا في هذا العمل
 * هو المال: أقُبض العربون أم اكتمل المبلغ، أم أُجِّل الحجز أم أُلغي.
 *
 * تحويل البيانات يقرأ المسدَّد لا التسمية القديمة: حجزٌ كُتب «مؤكدًا» وقد
 * استوفى مبلغه هو «مسدد كامل»، وآخر كُتب «تم الخروج» وعليه متبقٍ ليس كذلك.
 * التسمية القديمة كانت تُختار يدويًا فتخطئ، والمبلغ لا يخطئ.
 *
 * «مؤجل» و«ملغي» يبقيان بمفتاحيهما — لم يتغير فيهما إلا رسم الأخير («ملغى»)،
 * وهو عرضٌ لا يمسّ العمود.
 */
return new class extends Migration
{
    private const NEW_STATUSES = ['deposit_paid', 'paid_in_full', 'postponed', 'cancelled'];

    private const OLD_STATUSES = [
        'tentative',
        'pending_deposit',
        'confirmed',
        'checked_in',
        'checked_out',
        'postponed',
        'cancelled',
    ];

    /**
     * الحالتان الباقيتان على حالهما — تُستثنيان من التحويل بالمبلغ لأن
     * المؤجل والملغى لا يوصفان بسدادهما.
     */
    private const KEPT = ['postponed', 'cancelled'];

    public function up(): void
    {
        // توسيع الـ enum قبل النقل، وإلا رُفضت القيمة الجديدة على MySQL.
        $this->redefine([...self::OLD_STATUSES, ...self::NEW_STATUSES], 'deposit_paid');

        DB::table('bookings')
            ->whereNotIn('status', self::KEPT)
            ->whereColumn('paid_amount', '>=', 'total_amount')
            ->update(['status' => 'paid_in_full']);

        DB::table('bookings')
            ->whereNotIn('status', [...self::KEPT, 'paid_in_full'])
            ->update(['status' => 'deposit_paid']);

        $this->redefine(self::NEW_STATUSES, 'deposit_paid');
    }

    public function down(): void
    {
        $this->redefine([...self::OLD_STATUSES, ...self::NEW_STATUSES], 'confirmed');

        // لا مقابل قديمًا للحالتين المستحدثتين بدقة: ما اكتمل مبلغه يعود
        // «تم الخروج» — وهي الحالة التي كان الإيراد يُعترف به عندها — وما
        // دونه يعود «مؤكدًا»، وهو ما كان يُسجَّل به الحجز افتراضيًا.
        DB::table('bookings')->where('status', 'paid_in_full')->update(['status' => 'checked_out']);
        DB::table('bookings')->where('status', 'deposit_paid')->update(['status' => 'confirmed']);

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
            COMMENT 'مدفوع العربون | مسدد كامل | مؤجل | ملغى'");
    }
};
