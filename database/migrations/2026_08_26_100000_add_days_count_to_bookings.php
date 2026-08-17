<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * عدد أيام المناسبة في القاعة.
 *
 * المناسبة قد تمتد أيامًا متتالية (ليلة حنّاء ثم زواج)، وكانت تُسجَّل حجوزًا
 * منفصلة لكل يوم فيتفرّق عقدها وحسابها. العمود يجعلها حجزًا واحدًا يمتد
 * مداه الزمني على أيامه كلها، فيقفلها جميعًا في كشف التعارض ويُسعّرها معًا.
 *
 * يخصّ القاعات وحدها: الشاليه يُقاس بالليالي في nights، والعمودان لا يجتمعان
 * في حجز واحد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedSmallInteger('days_count')->nullable()->after('nights')
                ->comment('عدد أيام المناسبة — للقاعات فقط');
        });

        // الحجوزات القائمة كلها يوم واحد: تُملأ صراحةً حتى لا يبقى معنى العمود
        // معلّقًا على تفسير القيمة الفارغة في كل موضع يقرأه.
        DB::table('bookings')
            ->where('period', '!=', 'overnight')
            ->whereNull('days_count')
            ->update(['days_count' => 1]);
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('days_count');
        });
    }
};
