<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * عرض السعر وفاتورة المبيعات يُرسلان يدويًا (PDF) من رقم قسمهما، لكن المكتبة
 * لم تكن تحمل لهما قالبًا: عرض السعر يخرج بنصّه المدمج، وفاتورة المبيعات
 * تستعير قالب «فاتورة الحجز» فتصل وفيها «المسبح: —» و«الفترة» فارغة.
 *
 * لكلٍّ منهما الآن قالبٌ عام وقالبٌ للمسابح، يُعدَّلان من مكتبة الإشعارات.
 * قالبٌ موجود (ولو مؤرشفًا) يُترك كما هو.
 */
return new class extends Migration
{
    private const TEMPLATES = [
        ['general', 'quotation', 'إرسال عرض السعر — عام', [
            'مرحباً {name} 👋',
            'مرفق عرض السعر رقم {quotation_number} 📄',
            'الإجمالي: {total} ر.س',
            'صالح حتى: {valid_until}',
            'يسعدنا تواصلكم لأي استفسار.',
            '{business_name}',
        ]],
        ['pool', 'quotation', 'عرض سعر — المسابح', [
            'مرحباً {name} 👋',
            'مرفق عرض السعر رقم {quotation_number} 📄',
            'الإجمالي: {total} ر.س',
            'صالح حتى: {valid_until}',
            'يسعدنا تواصلكم لأي استفسار.',
            '{business_name} 🏊',
        ]],
        ['general', 'sale_invoice', 'فاتورة مبيعات — عام', [
            'مرحباً {name} 👋',
            'مرفق فاتورتكم رقم {invoice_number} 🧾',
            'التاريخ: {date}',
            'الإجمالي: {total}',
            'المسدَّد: {paid}',
            'المتبقي: {remaining}',
            'شكراً لتعاملكم مع {business_name}.',
        ]],
        ['pool', 'sale_invoice', 'فاتورة مبيعات — المسابح', [
            'مرحباً {name} 👋',
            'مرفق فاتورتكم رقم {invoice_number} 🧾',
            'التاريخ: {date}',
            '——————————————',
            'الإجمالي: {total}',
            'المسدَّد: {paid}',
            'المتبقي: {remaining}',
            '——————————————',
            'شكراً لتعاملكم مع {business_name} 🏊',
        ]],
    ];

    public function up(): void
    {
        $order = (int) DB::table('notification_templates')->max('sort_order');

        foreach (self::TEMPLATES as [$category, $event, $title, $lines]) {
            // Soft-deleted rows count: a template the office archived stays archived.
            $exists = DB::table('notification_templates')
                ->where('category', $category)
                ->where('event', $event)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('notification_templates')->insert([
                'category' => $category,
                'event' => $event,
                'title' => $title,
                'body' => implode("\n", $lines),
                'is_active' => true,
                'sort_order' => ++$order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::TEMPLATES as [$category, $event, , $lines]) {
            DB::table('notification_templates')
                ->where('category', $category)
                ->where('event', $event)
                ->where('body', implode("\n", $lines))
                ->delete();
        }
    }
};
