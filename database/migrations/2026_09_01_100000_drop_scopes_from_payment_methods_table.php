<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طرق الدفع مشتركة في النظام كله، فلا نطاق لها.
 *
 * أُنشئ الجدول بثلاثة أعمدة تحصر كل طريقة في أنظمة بعينها، حكايةً لما كانت
 * عليه الثوابت القديمة (كانت لكل نظام قائمته). لكن التفريق لا معنى له في
 * التشغيل: الشبكة شبكةٌ في القاعة والكاشير والسند سواء، والحصر يفرض على
 * المستخدم أن يتذكّر في أي شاشة تظهر ما أضافه — ويخلق طريقةً «موجودة ولا
 * تُرى» عند نسيان تأشير النطاق.
 *
 * الآن: الطريقة المفعّلة تظهر في كل شاشات النظام.
 */
return new class extends Migration
{
    private const SCOPES = ['for_bookings', 'for_sales', 'for_vouchers'];

    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn(self::SCOPES);
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            foreach (self::SCOPES as $scope) {
                // العودة بالجميع مفتوحًا: هو الحال الذي انتهى إليه النظام،
                // وإرجاع الحصر القديم يفترض معرفةً بنطاق كل طريقة لم تُحفظ.
                $table->boolean($scope)->default(true);
            }
        });
    }
};
