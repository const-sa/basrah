<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مصدران جديدان للنسخة (§18): ملفٌ رُفع من الخارج، ونسخةُ أمانٍ تُؤخذ تلقائيًا
 * قبل كل استعادة.
 *
 * والثانية هي الأهم: الاستعادة تكتب فوق القاعدة كلها، ومن استعاد ملفًا خاطئًا
 * لا يملك ما يعود إليه إن لم يُؤخذ له شيء قبل الكتابة. فتُؤخذ النسخة قبل
 * الاستعادة لا بعدها، وتُميَّز في السجل كي لا تُقرأ كأنها نسخةٌ يدوية عادية.
 */
return new class extends Migration
{
    private const TRIGGERS = ['manual', 'schedule', 'upload', 'pre_restore'];

    public function up(): void
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->enum('trigger', self::TRIGGERS)->default('schedule')->change();
        });
    }

    public function down(): void
    {
        // ما سُجّل بمصدرٍ يزول يعود «يدوية» — وإلا رفضت القاعدة قيمًا قائمة.
        DB::table('backups')
            ->whereIn('trigger', ['upload', 'pre_restore'])
            ->update(['trigger' => 'manual']);

        Schema::table('backups', function (Blueprint $table) {
            $table->enum('trigger', ['manual', 'schedule'])->default('schedule')->change();
        });
    }
};
