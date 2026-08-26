<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MeasureUnit;
use Illuminate\Database\Seeder;

/**
 * The pool build list as the client wrote it — filter, pump, the fittings that
 * go through the wall and the electrics that feed the light.
 *
 *   php artisan db:seed --class=PoolEquipmentSeeder
 *
 * Price, cost and balance are left at zero on purpose. The list carries no
 * money, and an invented figure sitting in a real file is worse than an empty
 * one — it gets quoted before anybody notices it was a guess. The balance is
 * zero for the same reason: these arrive on a purchase invoice, and seeding a
 * quantity would invent goods the warehouse never received. Each is quotable
 * and purchasable from the day it is seeded; selling one waits on the purchase
 * that brings it in.
 *
 * Re-running is safe. An item whose code is already in the file is left exactly
 * as it stands, so a price entered by hand is never reset by a second run.
 */
class PoolEquipmentSeeder extends Seeder
{
    public function run(): void
    {
        $pools = Department::firstWhere('code', 'POOLS');
        $piece = MeasureUnit::where('code', 'PCS')->value('id');

        // firstOrCreate rather than a lookup: the seeder stands on its own and
        // does not wait for CatalogSeeder to have drawn these categories first.
        $categories = [];
        foreach (['معدات وفلاتر', 'قطع غيار'] as $name) {
            $categories[$name] = ItemCategory::firstOrCreate(['name' => $name], ['is_active' => true])->id;
        }

        // [code, name, specification, category]. The specification is written
        // into the name as well as the description: the invoice line prints the
        // name alone, and «محابس بلاستيك» without its size is not an order
        // anybody can fill.
        $items = [
            ['FLT-002', 'فلتر رملي هايورد أمريكي', '18 بوصة', 'معدات وفلاتر'],
            ['PMP-003', 'مضخة هايورد 220 فولت أمريكي', '1 3/4 حصان', 'معدات وفلاتر'],
            ['SND-001', 'أكياس رمل ناعم', '50 كيلو', 'قطع غيار'],
            ['FTG-001', 'دفاعات (موجهات حائطية أسفل مستوى المياه)', '1.5 بوصة', 'قطع غيار'],
            ['FTG-002', 'تأسيس خط نوافير أعلى الجدران', '1.5 بوصة', 'قطع غيار'],
            ['DRN-001', 'صفاية أرضية', '25×25', 'قطع غيار'],
            ['LDR-001', 'سلم استيل مقاوم للصدأ', '2 درج', 'معدات وفلاتر'],
            ['LGT-002', 'كشاف ليد لطش أزرق', '24 وات', 'معدات وفلاتر'],
            ['TRF-001', 'محول كهرباء 220 إلى 12 فولت', '150 وات', 'معدات وفلاتر'],
            ['JBX-001', 'علبة توصيل', '12×12', 'قطع غيار'],
            ['VLV-001', 'محابس بلاستيك ضغط 80', '1.5 بوصة', 'قطع غيار'],
            ['SKM-001', 'اسكيمر لسحب جميع الشوائب على سطح الماء', '110 إنش', 'معدات وفلاتر'],
            ['VAC-001', 'خط مكنسة', '1.5 بوصة', 'قطع غيار'],
        ];

        foreach ($items as [$code, $name, $spec, $category]) {
            // withTrashed, or a code that was archived once would collide with
            // its own unique index on the way back in.
            $existing = Item::withTrashed()->firstWhere('code', $code);

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }

                continue;
            }

            Item::create([
                'code' => $code,
                'name' => "{$name} {$spec}",
                'item_category_id' => $categories[$category],
                'department_id' => $pools?->id,
                'type' => 'stock',
                'unit' => 'piece',
                'measure_unit_id' => $piece,
                'cost' => 0,
                'price' => 0,
                'tax_rate' => (float) config('operations.vat_rate', 15),
                'stock_qty' => 0,
                'reorder_point' => 0,
                'description' => $spec,
                'is_active' => true,
            ]);
        }
    }
}
