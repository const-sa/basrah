<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجل النسخ الاحتياطية (§18).
 *
 * النسخة على القرص وحدها لا تكفي: من أخذها، ومتى، وهل نجحت أم انقطعت،
 * وكم حجمها — أسئلةٌ تُطرح يوم يُحتاج إليها، ولا يجيب عنها اسم ملف. وهذا
 * الجدول هو الجواب، وهو أيضًا ما يُبنى عليه التنبيه حين تتأخّر النسخة.
 *
 * والفشل يُسجَّل كما يُسجَّل النجاح: نسخةٌ فشلت ولم تُذكر تعني أن أحدًا لن
 * يعرف أن الليلة مضت بلا نسخة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();

            $table->string('filename');
            $table->string('disk', 50)->default('local');
            $table->unsignedBigInteger('size')->default(0);

            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->text('error')->nullable();

            // يدويًا من الشاشة أم بالجدولة الليلية — والفرق يهمّ عند المراجعة.
            $table->enum('trigger', ['manual', 'schedule'])->default('schedule');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('driver', 20)->nullable()->comment('mysql / sqlite');
            $table->string('method', 20)->nullable()->comment('mysqldump / php / copy');
            $table->unsignedInteger('duration_ms')->default(0);

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
