<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

/**
 * باقات ابتدائية للقاعات.
 *
 * ⚠️ الأعداد والأسعار افتراضية للتشغيل — تُستبدل بباقات العميل الفعلية.
 */
class PackagesSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'name' => 'باقة الزفاف الفضية',
                'description' => 'باقة أساسية للأفراح المتوسطة',
                'price' => 18000,
                'items' => [
                    ['عدد المعازيم رجال', 300, 'شخص'],
                    ['عدد المعازيم نساء', 200, 'شخص'],
                    ['صبّابين', 4, 'فرد'],
                    ['مشرفات', 2, 'فرد'],
                    ['ضيافة قهوة وتمر', 500, 'شخص'],
                ],
            ],
            [
                'name' => 'باقة الزفاف الذهبية',
                'description' => 'باقة موسّعة تشمل العشاء والتنسيق',
                'price' => 32000,
                'items' => [
                    ['عدد المعازيم رجال', 500, 'شخص'],
                    ['عدد المعازيم نساء', 300, 'شخص'],
                    ['صبّابين', 6, 'فرد'],
                    ['مشرفات', 4, 'فرد'],
                    ['وجبات عشاء', 800, 'وجبة'],
                    ['كوش وتنسيق زهور', 1, 'طقم'],
                ],
            ],
            [
                'name' => 'باقة المناسبات الصغيرة',
                'description' => 'اجتماعات عائلية وتخرّج',
                'price' => 7500,
                'items' => [
                    ['عدد المعازيم رجال', 120, 'شخص'],
                    ['عدد المعازيم نساء', 80, 'شخص'],
                    ['صبّابين', 2, 'فرد'],
                    ['ضيافة قهوة وتمر', 200, 'شخص'],
                ],
            ],
        ];

        foreach ($packages as $i => $row) {
            $package = Package::updateOrCreate(
                ['name' => $row['name']],
                [
                    'description' => $row['description'],
                    'unit_id' => null,     // متاحة لكل القاعات
                    'price' => $row['price'],
                    'sort_order' => $i,
                    'is_active' => true,
                ],
            );

            $package->items()->delete();

            foreach ($row['items'] as $j => [$name, $qty, $unit]) {
                $package->items()->create([
                    'name' => $name,
                    'quantity' => $qty,
                    'unit_label' => $unit,
                    'sort_order' => $j,
                ]);
            }
        }
    }
}
