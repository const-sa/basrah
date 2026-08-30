<?php

namespace App\Support;

/**
 * أسماء الأعمدة بالعربية — يقرؤها السجل الرقابي ليعرض «المبلغ الإجمالي»
 * بدل «total_amount».
 *
 * الخريطة عامة لا خاصة بنموذج: أسماء الأعمدة في هذا النظام متسقة
 * (‏total_amount واحدة في الحجز والفاتورة والسند)، فخريطةٌ واحدة تكفي.
 * وما لا يُعرف يُعرض كما هو بدل أن يُخفى.
 */
class FieldLabels
{
    private const MAP = [
        'reference' => 'المرجع',
        'attachment_path' => 'المرفق',
        'number' => 'الرقم',
        'name' => 'الاسم',
        'code' => 'الرمز',
        'status' => 'الحالة',
        'type' => 'النوع',
        'scope' => 'نطاق الحجز',
        'period' => 'الفترة',
        'booking_date' => 'تاريخ الحجز',
        'check_out_date' => 'تاريخ الخروج',
        'nights' => 'عدد الليالي',
        'days_count' => 'عدد الأيام',
        'starts_at' => 'يبدأ',
        'ends_at' => 'ينتهي',
        'unit_id' => 'الوحدة',
        'unit_section_id' => 'القسم',
        'client_id' => 'العميل',
        'employee_id' => 'الموظف',
        'supplier_id' => 'المورد',
        'user_id' => 'المستخدم',
        'role_id' => 'الدور',
        'event_type_id' => 'نوع المناسبة',
        'package_id' => 'الباقة',
        'created_by' => 'أنشأه',
        'approved_by' => 'اعتمده',
        'received_by' => 'استلمه',
        'base_amount' => 'السعر الأساسي',
        'package_amount' => 'قيمة الباقة',
        'addons_amount' => 'قيمة الخدمات',
        'discount_amount' => 'الخصم',
        'total_amount' => 'الإجمالي',
        'deposit_amount' => 'العربون',
        'paid_amount' => 'المسدَّد',
        'amount' => 'المبلغ',
        'price' => 'السعر',
        'weekday_price' => 'سعر أيام الأسبوع',
        'weekend_price' => 'سعر نهاية الأسبوع',
        'day_prices' => 'أسعار الأيام',
        'quantity' => 'الكمية',
        'payment_method_id' => 'طريقة الدفع',
        'paid_on' => 'تاريخ السداد',
        'granted_on' => 'تاريخ المنح',
        'voucher_date' => 'تاريخ السند',
        'guests_count' => 'عدد الضيوف',
        'notes' => 'الملاحظات',
        'reason' => 'السبب',
        'cancellation_reason' => 'سبب الإلغاء',
        'cancelled_at' => 'وقت الإلغاء',
        'deleted_at' => 'وقت الحذف',
        'is_active' => 'مفعّل',
        'permissions' => 'الصلاحيات',
        'mobile' => 'الجوال',
        'phone' => 'الهاتف',
        'email' => 'البريد',
        'national_id' => 'رقم الهوية',
        'tax_number' => 'الرقم الضريبي',
        'basic_salary' => 'الراتب الأساسي',
        'bonus' => 'المكافأة',
        'gross' => 'الإجمالي',
        'net' => 'الصافي',
        'body' => 'النص',
        'terms' => 'الشروط',
        'sent_at' => 'وقت الإرسال',
        'signed_at' => 'وقت التوقيع',
        'source' => 'مصدر الحجز',
    ];

    public static function for(string $field): string
    {
        return self::MAP[$field] ?? $field;
    }
}
