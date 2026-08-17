<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * الحجز الجديد يُسجَّل مؤكدًا.
 *
 * «مبدئي» كان الافتراض، فيخرج كل حجز ناقصًا حتى يمرّ عليه موظف بزرّ تأكيد —
 * خطوةٌ لا تضيف معنى لأن الحجز لا يُسجَّل أصلًا إلا بعد الاتفاق مع العميل.
 * الحالة تبقى في القائمة لمن يحتاج حجزًا معلّقًا فعلًا، لكنها لم تعد الأصل.
 *
 * الحجوزات القائمة لا تُمسّ: حالتها واقعةٌ سُجِّلت، لا افتراضٌ يُصحَّح.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setDefault('confirmed');
    }

    public function down(): void
    {
        $this->setDefault('tentative');
    }

    /**
     * تغيير الافتراض وحده دون المساس بتعريف الـ enum — وصفه هنا نصًّا
     * لأن doctrine/dbal لا يفهم أعمدة enum في MySQL.
     *
     * والعبارة MySQL خالصة: SQLite لا يعرف MODIFY ولا ENUM أصلًا، فتُتخطّى
     * على غيره. ولا يضيع بذلك شيء — الافتراض يخصّ إدخالًا بلا حالة، وخدمات
     * الحجز تمرّر الحالة دائمًا، والاختبارات تعمل على SQLite.
     */
    private function setDefault(string $status): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE `bookings` MODIFY `status`
            ENUM('tentative', 'confirmed', 'completed', 'cancelled', 'no_show')
            NOT NULL DEFAULT '{$status}'
            COMMENT 'مبدئي ← مؤكد ← مكتمل ← ملغي ← لم يحضر'");
    }
};
