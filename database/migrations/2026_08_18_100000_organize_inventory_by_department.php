<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تنظيم المستودع والمبيعات بالأقسام.
 *
 * القسم هنا نشاط تجاري مستقل (المسابح، القاعات…) لا قسم رجال/نساء
 * داخل وحدة — ذاك في جدول unit_sections. كل صنف يتبع قسمًا، وكل فاتورة
 * تُنسب لقسم، فتُقاس ربحية كل نشاط على حدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('id');
            $table->text('description')->nullable()->after('name');
            // القسم الذي تُفتح عليه شاشة الفواتير افتراضيًا
            $table->boolean('sells')->default(false)->after('description')
                ->comment('هل لهذا القسم مبيعات وفواتير؟');
            $table->unsignedInteger('sort_order')->default(0)->after('sells');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('item_category_id')
                ->constrained('departments')->nullOnDelete();
            $table->index('department_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('unit_id')
                ->constrained('departments')->nullOnDelete();
            $table->index('department_id');
        });

        // مركز تكلفة لكل قسم — به تُقاس ربحية النشاط
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('unit_id')
                ->constrained('departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cost_centers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn(['code', 'description', 'sells', 'sort_order']);
        });
    }
};
