<?php

namespace Database\Seeders;

use App\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * جرد الوحدات من §1.2 من وثيقة النطاق.
 *
 * ملاحظات معلّقة على العميل (§القسم الخامس):
 *  - أسماء القاعة الأولى والشاليه الثاني تحتاج تأكيدًا.
 *  - تفصيل أقسام «مون لايت» (5) و«فورسيزون» (3) غير مؤكد — أُدخلت مبدئيًا
 *    كأقسام رجال/نساء + أجنحة، وتُعدَّل من شاشة الوحدات عند ورود التفصيل.
 */
class UnitsSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            [
                'code' => 'HALL-01',
                'name' => 'قاعة المشام',
                'type' => 'hall',
                'notes' => 'الاسم يحتاج تأكيدًا من العميل + خدمات خارجية',
                'sections' => [
                    ['name' => 'قسم الرجال', 'gender' => 'men'],
                    ['name' => 'قسم النساء', 'gender' => 'women'],
                ],
            ],
            [
                'code' => 'HALL-02',
                'name' => 'قاعة روكانا',
                'type' => 'hall',
                'notes' => 'تقسيم القاعة يحتاج تأكيدًا',
                'sections' => [
                    ['name' => 'قسم الرجال', 'gender' => 'men'],
                    ['name' => 'قسم النساء', 'gender' => 'women'],
                ],
            ],
            [
                'code' => 'CH-FOUR',
                'name' => 'شاليه فورسيزون',
                'type' => 'chalet',
                'sections' => [
                    ['name' => 'قسم الرجال', 'gender' => 'men'],
                    ['name' => 'قسم النساء', 'gender' => 'women'],
                ],
            ],
            [
                'code' => 'CH-JOHN',
                'name' => 'شاليه جون حسين',
                'type' => 'chalet',
                'notes' => 'الاسم يحتاج تأكيدًا من العميل',
                'sections' => [
                    ['name' => 'قسم الرجال', 'gender' => 'men'],
                    ['name' => 'قسم النساء', 'gender' => 'women'],
                ],
            ],
            [
                'code' => 'CH-BSR1',
                'name' => 'شاليه البصرة',
                'type' => 'chalet',
                'sections' => [
                    ['name' => 'قسم الرجال', 'gender' => 'men'],
                    ['name' => 'قسم النساء', 'gender' => 'women'],
                ],
            ],
            [
                'code' => 'CH-MOON',
                'name' => 'شاليه مون لايت',
                'type' => 'chalet',
                'notes' => 'خمسة أقسام — التفصيل مبدئي بانتظار تأكيد العميل',
                'sections' => [
                    ['name' => 'قسم الرجال', 'gender' => 'men'],
                    ['name' => 'قسم النساء', 'gender' => 'women'],
                    ['name' => 'جناح 1', 'gender' => 'mixed'],
                    ['name' => 'جناح 2', 'gender' => 'mixed'],
                    ['name' => 'جناح 3', 'gender' => 'mixed'],
                ],
            ],
            [
                'code' => 'CH-BSR2',
                'name' => 'شاليه البصرة 2',
                'type' => 'chalet',
                'notes' => 'ترميز فريد لتفادي الالتباس مع شاليه البصرة',
                'sections' => [
                    ['name' => 'قسم الرجال', 'gender' => 'men'],
                    ['name' => 'قسم النساء', 'gender' => 'women'],
                ],
            ],
            [
                'code' => 'CH-LULU',
                'name' => 'شاليه لولو',
                'type' => 'chalet',
                'sections' => [
                    ['name' => 'قسم الرجال', 'gender' => 'men'],
                    ['name' => 'قسم النساء', 'gender' => 'women'],
                ],
            ],
        ];

        foreach ($units as $i => $data) {
            $sections = $data['sections'];
            unset($data['sections']);

            $unit = Unit::updateOrCreate(
                ['code' => $data['code']],
                array_merge($data, [
                    'bookable_mode' => 'both',
                    // الافتراضي «حجب» حتى يحسم العميل قاعدة الخصوصية لكل وحدة (§1.1)
                    'privacy_mode' => 'exclusive',
                    'sort_order' => $i,
                    'is_active' => true,
                ]),
            );

            foreach ($sections as $j => $section) {
                $unit->sections()->updateOrCreate(
                    ['name' => $section['name']],
                    array_merge($section, ['sort_order' => $j, 'is_active' => true]),
                );
            }
        }
    }
}
