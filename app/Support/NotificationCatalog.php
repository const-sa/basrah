<?php

namespace App\Support;

/**
 * فهرس مكتبة الإشعارات: الأقسام والمناسبات والمتغيّرات المتاحة لكل مناسبة.
 *
 * مرجعٌ واحد تقرأ منه الواجهة والتحقّق والبذور معًا. إضافة قالب جديد
 * لاحقًا لا تتطلّب إلا سطرًا هنا — لا تعديلًا في أربعة مواضع متفرّقة.
 */
class NotificationCatalog
{
    /**
     * أقسام المكتبة. القسم يحدّد «أين» يُستعمل القالب لا «متى».
     *
     * @return array<string, array{label: string, hint: string}>
     */
    public static function categories(): array
    {
        return [
            'general' => ['label' => 'عام', 'hint' => 'رسائل لا ترتبط بنوع وحدة بعينه'],
            'chalet' => ['label' => 'الشاليهات', 'hint' => 'حجوزات الشاليهات والإقامات'],
            'hall' => ['label' => 'القاعات', 'hint' => 'حجوزات القاعات والمناسبات'],
            'pool' => ['label' => 'المسابح', 'hint' => 'حجوزات المسابح والفترات'],
        ];
    }

    /**
     * مناسبات الإرسال. المناسبة تحدّد «متى» يُرسل القالب.
     *
     * @return array<string, array{label: string, hint: string, auto: bool}>
     */
    public static function events(): array
    {
        return [
            'welcome' => ['label' => 'ترحيب بالعميل', 'hint' => 'عند إضافة عميل جديد', 'auto' => true],
            'booking_confirm' => ['label' => 'تأكيد الحجز', 'hint' => 'فور اعتماد الحجز', 'auto' => true],
            'invoice' => ['label' => 'الفاتورة', 'hint' => 'عند إرسال فاتورة الحجز', 'auto' => true],
            'reminder' => ['label' => 'تذكير بالموعد', 'hint' => 'قبل موعد الحجز', 'auto' => true],
            'balance_reminder' => ['label' => 'تذكير بالمتبقي', 'hint' => 'عند وجود مبلغ غير مسدَّد', 'auto' => true],
            'payment' => ['label' => 'إشعار سداد', 'hint' => 'عند استلام دفعة', 'auto' => true],
            'cancellation' => ['label' => 'إشعار إلغاء', 'hint' => 'عند إلغاء الحجز', 'auto' => true],
            'contract' => ['label' => 'إرسال العقد', 'hint' => 'عند إصدار العقد', 'auto' => true],
            'custom' => ['label' => 'رسالة حرّة', 'hint' => 'إرسال يدوي من المكتبة', 'auto' => false],
        ];
    }

    /**
     * المتغيّرات المتاحة داخل نصّ القالب.
     *
     * الترحيب لا يعرف حجزًا بعد، فمتغيّرات الحجز لا تُعرض له كي لا يكتب
     * الموظف {reference} في رسالةٍ تصل وفيها فراغ.
     *
     * @return array<string, array<string, string>>
     */
    public static function variables(): array
    {
        $client = [
            'name' => 'اسم العميل',
            'business_name' => 'اسم النشاط',
            'mobile' => 'جوال العميل',
        ];

        $booking = $client + [
            'reference' => 'رقم الحجز',
            'unit' => 'اسم الوحدة',
            'date' => 'تاريخ الحجز',
            'period' => 'الفترة',
            'total' => 'إجمالي الحجز',
            'paid' => 'المسدَّد',
            'remaining' => 'المتبقي',
        ];

        return [
            'welcome' => $client,
            'custom' => $client,
            'contract' => $booking + ['contract_number' => 'رقم العقد'],
            'payment' => $booking + ['amount' => 'مبلغ الدفعة'],
            'cancellation' => $booking + ['reason' => 'سبب الإلغاء'],
            'booking_confirm' => $booking,
            'invoice' => $booking,
            'reminder' => $booking,
            'balance_reminder' => $booking,
        ];
    }

    /** @return list<string> */
    public static function categoryKeys(): array
    {
        return array_keys(static::categories());
    }

    /** @return list<string> */
    public static function eventKeys(): array
    {
        return array_keys(static::events());
    }

    /**
     * القسم الموافق لنوع الوحدة (hall / chalet). ما عداه يقع تحت «عام».
     */
    public static function categoryForUnitType(?string $unitType): string
    {
        return in_array($unitType, ['hall', 'chalet', 'pool'], true) ? $unitType : 'general';
    }

    /**
     * الفهرس كاملاً للواجهة.
     *
     * @return array<string, mixed>
     */
    public static function forFrontend(): array
    {
        $shape = fn (array $map) => collect($map)
            ->map(fn ($meta, $key) => ['key' => $key] + $meta)
            ->values()
            ->all();

        return [
            'categories' => $shape(static::categories()),
            'events' => $shape(static::events()),
            'variables' => collect(static::variables())
                ->map(fn ($vars) => collect($vars)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all())
                ->all(),
        ];
    }
}
