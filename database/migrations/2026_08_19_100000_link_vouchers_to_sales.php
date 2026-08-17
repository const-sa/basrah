<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط سند القبض بالفاتورة التي يسدّدها.
 *
 * فاتورة «على الحساب» تُسدَّد على دفعات، وكل دفعة سند قبض مستقل.
 * بلا هذا الربط لا يُعرف ما سُدِّد من فاتورة بعينها، فيبقى «مسدد جزئي»
 * حالة لا يمكن قياسها ولا عرض سنداتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('supplier_id')
                ->constrained('sales')->nullOnDelete()
                ->comment('الفاتورة التي يسدّدها السند');
        });
    }

    public function down(): void
    {
        Schema::table('vouchers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });
    }
};
