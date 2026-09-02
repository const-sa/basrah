<?php

namespace App\Support;

use App\Models\Setting;

/**
 * ضريبة القيمة المضافة — قاعدةٌ واحدة يقرأها النظام كله.
 *
 * كانت القاعدة مكتوبةً مرتين: مرةً في فاتورة الحجز ومرةً في عمود الضريبة
 * بسجل الحجوزات، وشاشة الحجز نفسها لا تعرفها أصلًا — فيرى الموظف إجماليًا
 * بلا ضريبة ثم تخرج الفاتورة بسطر ضريبة لم يره. فجُمعت هنا.
 *
 * والضريبة تُضاف فوق المبلغ لا تُستخرج منه: السعر المُدخَل في شاشة الأسعار
 * سعرٌ صافٍ، والضريبة تُحتسب عليه ثم تُجمع، فيخرج الإجمالي شاملًا. ولذلك
 * صار المخزَّن في total_amount شاملًا للضريبة — هو ما يدفعه العميل فعلًا —
 * فتُستخرج منه لاحقًا في الفاتورة والسجل بـ of() و net().
 *
 * وشرطها الرقم الضريبي: بلا تسجيل لا فاتورة ضريبية، فتخرج الورقة عاديةً
 * بلا سطر ضريبة، لا فاتورةً ضريبية بحقلٍ فارغ.
 */
class Vat
{
    /**
     * مفتاح الإعدادات في الحاوية — تُقرأ في كل سطر تسعيرة وكل صف في
     * السجل، فقراءتها من الجدول في كل مرة استعلامٌ بلا داعٍ.
     *
     * والحفظ في الحاوية لا في خاصية ساكنة: الحاوية تُبنى من جديد لكل طلب
     * ولكل اختبار، فلا تتسرّب نسبة اختبارٍ إلى الذي يليه.
     */
    private const CACHE_KEY = 'vat.settings';

    /**
     * تُنسى عند حفظ الإعدادات، فلا تبقى النسبة القديمة سارية بقية الطلب.
     */
    public static function forget(): void
    {
        app()->forgetInstance(self::CACHE_KEY);
    }

    private static function settings(): Setting
    {
        if (! app()->bound(self::CACHE_KEY)) {
            app()->instance(self::CACHE_KEY, Setting::current());
        }

        return app(self::CACHE_KEY);
    }

    /**
     * هل على المبالغ ضريبة؟ التفعيل والرقم والنسبة شروطٌ مجتمعة.
     */
    public static function applies(): bool
    {
        $settings = self::settings();

        return (bool) $settings->tax_enabled
            && filled($settings->tax_number)
            && (float) $settings->tax_rate > 0;
    }

    /**
     * النسبة السارية — صفرٌ حين لا ضريبة، فتُضرب بلا شرطٍ قبلها.
     */
    public static function rate(): float
    {
        return self::applies() ? (float) self::settings()->tax_rate : 0.0;
    }

    /**
     * نسبة صنفٍ بعينه بعد المرور على المفتاح العام.
     *
     * الأصناف تحمل نسبها في جدولها، وشاشات المشتريات والمبيعات ونقطة البيع
     * وعروض الأسعار كانت تقرؤها منه مباشرةً — فيُطفأ المفتاح في الإعدادات
     * وتبقى الضريبة تُحتسب وتُطبع في فواتيرها كأن شيئًا لم يكن. فما من نسبة
     * تُقرأ إلا من هنا: المفتاح مطفأ ⇒ صفر، مهما كان المخزَّن على الصنف.
     */
    public static function rateOf(float|string|null $itemRate): float
    {
        return self::applies() ? max(0.0, (float) $itemRate) : 0.0;
    }

    /**
     * الضريبة المستحقة على مبلغٍ صافٍ بنسبة صنفه.
     */
    public static function onAt(float $net, float|string|null $itemRate): float
    {
        return round(round($net, 2) * self::rateOf($itemRate) / 100, 2);
    }

    /**
     * الضريبة المستحقة على مبلغٍ صافٍ — تُضاف فوقه.
     */
    public static function on(float $net): float
    {
        return round(round($net, 2) * self::rate() / 100, 2);
    }

    /**
     * المبلغ الصافي مضافًا إليه ضريبته.
     */
    public static function gross(float $net): float
    {
        return round(round($net, 2) + self::on($net), 2);
    }

    /**
     * المبلغ قبل الضريبة من إجماليٍ شاملها — لقراءة ما خُزِّن شاملًا.
     */
    public static function net(float $gross): float
    {
        $rate = self::rate();

        return $rate > 0 ? round($gross / (1 + $rate / 100), 2) : round($gross, 2);
    }

    /**
     * حصة الضريبة من إجماليٍ شاملها — لقراءة ما خُزِّن شاملًا.
     */
    public static function of(float $gross): float
    {
        return round(round($gross, 2) - self::net($gross), 2);
    }

    /**
     * تفصيل تسعيرةٍ صافية كما تُعرَض: الصافي، والضريبة فوقه، والإجمالي.
     *
     * $taxable is the document's own answer. It overrides the rate downwards
     * but never upwards: a booking for an exempt body is priced without tax
     * even though the business is registered, and nothing is taxed while the
     * system-wide switch is off, whatever the document says.
     *
     * @return array{is_taxable: bool, tax_rate: float, net_amount: float, tax_amount: float, total_amount: float}
     */
    public static function breakdown(float $net, bool $taxable = true): array
    {
        $net = round($net, 2);
        $tax = $taxable ? self::on($net) : 0.0;

        return [
            'is_taxable' => $taxable && self::applies(),
            'tax_rate' => $taxable ? self::rate() : 0.0,
            'net_amount' => $net,
            'tax_amount' => $tax,
            'total_amount' => round($net + $tax, 2),
        ];
    }
}
