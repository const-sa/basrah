<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نطاق وصول المستخدم إلى الوحدات (§الطبقة أ - بند 6):
 * «مستخدم لكل قاعة وشاليه يرى وحدته فقط».
 *
 * الصلاحية (permissions) تحدّد *ما* يستطيع المستخدم فعله،
 * وهذا الجدول يحدّد *على أي وحدة* يستطيع فعله.
 * المستخدم غير المرتبط بأي وحدة وله `units.scope_all` يرى كل الوحدات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('units')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'unit_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // true = يرى كل الوحدات بلا تقييد (المالك والمحاسب)، false = مقيّد بجدول unit_user
            $table->boolean('has_all_units')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('has_all_units');
        });

        Schema::dropIfExists('unit_user');
    }
};
