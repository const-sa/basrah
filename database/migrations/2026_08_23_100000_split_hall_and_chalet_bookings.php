<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * فصل حجز الشاليه عن حجز القاعة.
 *
 * القاعة تُحجز ليوم واحد بفترة (صباحي/مسائي/يوم كامل)، والشاليه يُحجز بإقامة
 * ممتدة: تاريخ دخول وتاريخ خروج وعدد ليالٍ. الجدول يبقى واحدًا لأن الدفعات
 * والقيود المحاسبية والعقود كلها معلَّقة على booking_id، وفصل الجدول يعني
 * كسر هذه الروابط بلا مقابل. الفصل يجري في المسارات والشاشات والخدمات.
 *
 * booking_date  =  تاريخ الدخول في الشاليه، وتاريخ المناسبة في القاعة.
 * check_out_date + nights تخصّان الشاليه وحده وتبقيان null في القاعة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->date('check_out_date')->nullable()->after('booking_date')
                ->comment('تاريخ الخروج — للشاليهات فقط');
            $table->unsignedSmallInteger('nights')->nullable()->after('check_out_date')
                ->comment('عدد الليالي — للشاليهات فقط');
        });

        // الحجوزات القائمة على الشاليهات كانت بفترة يوم واحد: تُقرأ الآن كليلة
        // واحدة تنتهي في اليوم التالي، فتظهر في الشاشة الجديدة بلا بيانات ناقصة.
        // الحساب في PHP لا في SQL لأن دوال التواريخ تختلف بين MySQL و SQLite.
        $chaletIds = DB::table('units')->where('type', 'chalet')->pluck('id');

        if ($chaletIds->isEmpty()) {
            return;
        }

        DB::table('bookings')
            ->whereIn('unit_id', $chaletIds)
            ->whereNull('check_out_date')
            ->select('id', 'booking_date')
            ->orderBy('id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('bookings')->where('id', $row->id)->update([
                        'check_out_date' => CarbonImmutable::parse($row->booking_date)->addDay()->toDateString(),
                        'nights' => 1,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['check_out_date', 'nights']);
        });
    }
};
