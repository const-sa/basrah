<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سعر مستقل لكل يوم من أيام الأسبوع.
 *
 * الشاليه يُباع بالليلة، وسوق الليالي لا ينقسم قسمين فقط: الخميس يُطلب بسعر،
 * والجمعة بسعر أعلى، والأحد بأدنى الأسعار. ثنائية «أيام الأسبوع / نهاية
 * الأسبوع» تجبر الإدارة على تسوية خمسة أيام مختلفة الطلب بسعر واحد.
 *
 * العمود خريطة {رقم اليوم: السعر} برقم يوم Carbon (0 الأحد … 6 السبت)، ويقبل
 * غياب اليوم أو قيمته null فيرجع ذلك اليوم إلى weekday_price/weekend_price.
 * ولذلك بقي العمودان القديمان: هما القاعدة، وهذا استثناء فوقها لا بديل عنها،
 * فلا تنكسر تسعيرة قاعة قائمة ولا تُفقد بيانات محفوظة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unit_prices', function (Blueprint $table) {
            $table->json('day_prices')->nullable()->after('weekend_price');
        });
    }

    public function down(): void
    {
        Schema::table('unit_prices', function (Blueprint $table) {
            $table->dropColumn('day_prices');
        });
    }
};
