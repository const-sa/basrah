<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تقسيم مكتبة القوالب: قسمٌ (شاليهات/قاعات/مسابح/عام) ومناسبةٌ (ترحيب/تأكيد/فاتورة…).
 *
 * المكتبة كانت قائمةً مسطّحة، ومع كل قالب جديد تزداد صعوبة العثور على
 * القالب الذي يُرسله النظام تلقائيًا. القسم والمناسبة يجعلان القالب
 * قابلاً للاستدعاء برمجيًا: النظام يسأل «قالب الفاتورة للشاليهات» فيجده.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->string('category', 40)->default('general')->after('id');
            $table->string('event', 40)->nullable()->after('category');
            $table->boolean('is_active')->default(true)->after('body');
            $table->integer('sort_order')->default(0)->after('is_active');

            $table->index(['category', 'event']);
        });
    }

    public function down(): void
    {
        Schema::table('notification_templates', function (Blueprint $table) {
            $table->dropIndex(['category', 'event']);
            $table->dropColumn(['category', 'event', 'is_active', 'sort_order']);
        });
    }
};
