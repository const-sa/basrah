<?php

namespace Database\Seeders;

use App\Models\EventType;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * أنواع المناسبات الشائعة لكل قاعة — نقطة بداية يعدّلها المشغّل.
 *
 * الأنواع تُنسخ لكل قاعة لأن النوع صار يتبع قاعته ويحمل سعرها: «زواج» في
 * قاعة المشام سجلٌّ غير «زواج» في روكانا، ولكلٍّ سعره.
 *
 * الأسعار صفر عمدًا: لا يعرف البذر كم تبيع كل قاعة مناسباتها، والصفر يعني
 * «اتّبع تسعيرة القاعة» فيبقى النظام عاملًا حتى يُدخل المشغّل أسعاره.
 */
class EventTypesSeeder extends Seeder
{
    private const TYPES = [
        ['name' => 'زواج', 'color' => 'rose'],
        ['name' => 'ملكة', 'color' => 'violet'],
        ['name' => 'خطوبة', 'color' => 'amber'],
        ['name' => 'تخرّج', 'color' => 'sky'],
        ['name' => 'عقيقة', 'color' => 'emerald'],
        ['name' => 'اجتماع', 'color' => 'slate'],
    ];

    public function run(): void
    {
        // الشاليه لا مناسبة فيه تُصنَّف — إقامةٌ بالليالي لا حفل بفترة.
        foreach (Unit::where('type', 'hall')->pluck('id') as $hallId) {
            foreach (self::TYPES as $i => $type) {
                EventType::updateOrCreate(
                    ['unit_id' => $hallId, 'name' => $type['name']],
                    [
                        'color' => $type['color'],
                        'price' => 0,
                        'sort_order' => $i + 1,
                        'is_active' => true,
                    ],
                );
            }
        }
    }
}
