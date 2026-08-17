<?php

use App\Models\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * طرق الدفع تصير جدولًا بدل ثوابت في الكود.
 *
 * كانت مكتوبة في ثلاثة ثوابت متباعدة (BookingPayment، Sale، Voucher) وفي
 * ثلاثة أعمدة enum وفي ثلاث خرائط `match` محاسبية وفي قوائم اختيار مكتوبة
 * يدويًا في الواجهة. فكانت الطريقة الواحدة تُضاف في موضع وتُنسى في خمسة،
 * وأخطرها الخريطة المحاسبية: أي مفتاح غير معروف كان يهبط على
 * `default => CASH` صامتًا، فتُقيَّد حوالة بنكية في الصندوق النقدي.
 *
 * الآن: صفٌّ واحد لكل طريقة يحمل حسابها ونطاقها، وأعمدة الأنظمة الثلاثة
 * مفاتيح أجنبية إليه، وعمود `method` القديم يُحذف بلا بقية.
 */
return new class extends Migration
{
    /**
     * الجداول التي تحمل طريقة دفع، ولكلٍّ افتراضٌ للصفوف القائمة عند
     * تعذّر مطابقة كودها القديم.
     */
    private const TABLES = [
        'booking_payments' => 'cash',
        'sales' => 'cash',
        'vouchers' => 'cash',
    ];

    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');

            // أين يهبط المقبوض في الدفاتر — صريحٌ لكل طريقة، فلا يبقى
            // حسابُ الطريقة الجديدة رهنَ فرعٍ افتراضي في الكود.
            $table->enum('deposits_to', ['cash', 'bank'])->default('cash');

            // آجلة: لا تُقبض عند الإصدار افتراضيًا، ومرتجعها على الذمم
            // لا على الخزينة. «على الحساب» وحدها كذلك اليوم.
            $table->boolean('is_credit')->default(false);

            // نطاق الاستعمال — الحوالة تصلح للثلاثة، و«على الحساب» للمبيعات وحدها.
            $table->boolean('for_bookings')->default(true);
            $table->boolean('for_sales')->default(true);
            $table->boolean('for_vouchers')->default(true);

            $table->boolean('is_active')->default(true);

            // طريقة أساسية لا تُحذف: النظام يعتمدها كافتراض أو يبني عليها
            // سلوكًا (النقد افتراض كل خدمة، والآجل شرط فواتير الذمم).
            $table->boolean('is_system')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        DB::table('payment_methods')->insert(PaymentMethod::defaults());

        $ids = DB::table('payment_methods')->pluck('id', 'code');

        foreach (self::TABLES as $table => $fallback) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('payment_method_id')->nullable()->after('id')->constrained('payment_methods');
            });

            foreach ($ids as $code => $id) {
                DB::table($table)->where('method', $code)->update(['payment_method_id' => $id]);
            }

            // صفٌّ بكود لا مقابل له (بيانات أقدم من الأعمدة) يأخذ الافتراض
            // بدل أن يبقى فارغًا فيسقط من كل تقرير.
            DB::table($table)->whereNull('payment_method_id')->update(['payment_method_id' => $ids[$fallback]]);

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('method');
            });
        }
    }

    public function down(): void
    {
        $codes = DB::table('payment_methods')->pluck('code', 'id');

        foreach (self::TABLES as $table => $fallback) {
            Schema::table($table, function (Blueprint $t) use ($fallback) {
                $t->string('method', 50)->default($fallback)->after('id');
            });

            foreach ($codes as $id => $code) {
                DB::table($table)->where('payment_method_id', $id)->update(['method' => $code]);
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['payment_method_id']);
                $t->dropColumn('payment_method_id');
            });
        }

        Schema::dropIfExists('payment_methods');
    }
};
