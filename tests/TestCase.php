<?php

namespace Tests;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * ساعة الاختبارات مثبَّتة على يوم واحد.
     *
     * تواريخ الحجوزات في الاختبارات ثابتة (2026-09 وما بعدها)، والنظام يرفض
     * الحجز في يوم مضى. بلا تثبيت الساعة تنجح هذه الاختبارات اليوم وتنهار
     * وحدها حين يتجاوزها التقويم — فشلٌ يظهر بلا تعديل في الكود ويُضيّع وقتًا
     * في البحث عن سببه. التثبيت يجعل «اليوم» ثابتًا فتبقى النتيجة واحدة.
     */
    protected const TEST_NOW = '2026-08-16 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::TEST_NOW);
    }

    /**
     * معرّف طريقة دفع بكودها.
     *
     * الطرق تُنشئها الهجرة نفسها، فهي موجودة في كل اختبار بلا بذر. والاختبارات
     * تعرف الكود («cash») لا المعرّف، فيبقى هذا الجسر بينهما في موضع واحد.
     */
    protected function paymentMethodId(string $code = PaymentMethod::CASH): int
    {
        return (int) PaymentMethod::where('code', $code)->value('id');
    }
}
