<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every contract went out as «مرفق عقد الحجز رقم …»: the pools had no contract
 * template of their own, so an installation or maintenance contract fell back
 * to the general one and arrived with a blank booking, unit and date.
 *
 * The seeded contract templates now name the contract by {contract_title}, and
 * the pools get their own. A wording the office has edited is left alone.
 */
return new class extends Migration
{
    private const REWORDED = [
        'general' => [
            'old' => ['مرحباً {name} 👋', 'مرفق عقد الحجز رقم {reference} 📄', 'رقم العقد: {contract_number}', 'الوحدة: {unit}', 'التاريخ: {date} — {period}', 'نرجو الاطلاع والتأكيد.', '{business_name}'],
            'new' => ['مرحباً {name} 👋', 'مرفق {contract_title} 📄', 'رقم العقد: {contract_number}', 'رقم الحجز: {reference}', 'الوحدة: {unit}', 'التاريخ: {date} — {period}', 'نرجو الاطلاع والتأكيد.', '{business_name}'],
        ],
        'chalet' => [
            'old' => ['مرحباً {name} 👋', 'مرفق عقد حجز الشاليه رقم {reference} 📄', 'رقم العقد: {contract_number}', 'الشاليه: {unit}', 'تاريخ الدخول: {date} — {period}', 'نرجو الاطلاع والتأكيد.', '{business_name} 🏝️'],
            'new' => ['مرحباً {name} 👋', 'مرفق {contract_title} 📄', 'رقم العقد: {contract_number}', 'رقم الحجز: {reference}', 'الشاليه: {unit}', 'تاريخ الدخول: {date} — {period}', 'نرجو الاطلاع والتأكيد.', '{business_name} 🏝️'],
        ],
        'hall' => [
            'old' => ['مرحباً {name} 👋', 'مرفق عقد حجز القاعة رقم {reference} 📄', 'رقم العقد: {contract_number}', 'القاعة: {unit}', 'تاريخ المناسبة: {date} — {period}', 'نرجو الاطلاع والتأكيد.', '{business_name} 🏛️'],
            'new' => ['مرحباً {name} 👋', 'مرفق {contract_title} 📄', 'رقم العقد: {contract_number}', 'رقم الحجز: {reference}', 'القاعة: {unit}', 'تاريخ المناسبة: {date} — {period}', 'نرجو الاطلاع والتأكيد.', '{business_name} 🏛️'],
        ],
    ];

    private const POOL = ['مرحباً {name} 👋', 'مرفق {contract_title} 📄', 'رقم العقد: {contract_number}', 'نرجو الاطلاع والتأكيد.', '{business_name} 🏊'];

    public function up(): void
    {
        foreach (self::REWORDED as $category => $wording) {
            $this->reword($category, $wording['old'], $wording['new']);
        }

        // Soft-deleted rows count: a template the office archived stays archived.
        $hasPool = DB::table('notification_templates')
            ->where('event', 'contract')
            ->where('category', 'pool')
            ->exists();

        if (! $hasPool) {
            DB::table('notification_templates')->insert([
                'category' => 'pool',
                'event' => 'contract',
                'title' => 'إرسال عقد مسابح',
                'body' => implode("\n", self::POOL),
                'is_active' => true,
                'sort_order' => (int) DB::table('notification_templates')->max('sort_order') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::REWORDED as $category => $wording) {
            $this->reword($category, $wording['new'], $wording['old']);
        }

        DB::table('notification_templates')
            ->where('event', 'contract')
            ->where('category', 'pool')
            ->where('body', implode("\n", self::POOL))
            ->delete();
    }

    /** @param list<string> $from @param list<string> $to */
    private function reword(string $category, array $from, array $to): void
    {
        DB::table('notification_templates')
            ->where('event', 'contract')
            ->where('category', $category)
            ->where('body', implode("\n", $from))
            ->update(['body' => implode("\n", $to), 'updated_at' => now()]);
    }
};
