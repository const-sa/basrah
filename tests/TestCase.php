<?php

namespace Tests;

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
}
