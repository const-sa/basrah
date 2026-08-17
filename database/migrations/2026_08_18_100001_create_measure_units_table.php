<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * وحدات القياس (قطعة، متر، متر مربع، ساعة…).
 *
 * سُمّي الجدول measure_units لا units لأن units محجوز للقاعات والشاليهات.
 *
 * نُقلت من ثابت في الكود إلى جدول لأن نشاط المسابح يحتاج وحدات لا
 * تُعرف مسبقًا (لفة، طن، برميل…)، وإضافتها يجب أن تكون إدخال بيانات
 * لا تعديل مصدر ونشر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('measure_units', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('symbol')->nullable();

            /**
             * هل تقبل هذه الوحدة كميات كسرية؟
             * «متر مربع» نعم، و«قطعة» لا — القاعدة تتبع الوحدة لا نوع الصنف،
             * فهو الأدق: صنف مخزني يُباع بالمتر يقبل الكسر.
             */
            $table->boolean('allows_fraction')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('measure_unit_id')->nullable()->after('unit')
                ->constrained('measure_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('measure_unit_id');
        });

        Schema::dropIfExists('measure_units');
    }
};
