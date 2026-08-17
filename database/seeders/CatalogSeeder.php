<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\MeasureUnit;
use Illuminate\Database\Seeder;

/**
 * أصناف نشاط المسابح — بيع وصيانة.
 *
 * نشاط مستقل بمنتجاته، لا علاقة له بالقاعات والشاليهات، فأصنافه
 * معدّات ومواد وخدمات صيانة لا ضيافة.
 *
 * ⚠️ الأسعار والتكاليف افتراضية للتشغيل — تُستبدل بقائمة العميل.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        // كل أصناف هذه البذرة تتبع قسم المسابح
        $pools = Department::firstWhere('code', 'POOLS');
        $units = MeasureUnit::pluck('id', 'code');

        $categories = [];
        foreach (['معدات وفلاتر', 'مواد كيميائية', 'قطع غيار', 'خامات بالقياس', 'خدمات صيانة', 'مشاريع'] as $name) {
            $categories[$name] = ItemCategory::updateOrCreate(['name' => $name], ['is_active' => true])->id;
        }

        // الوحدة القديمة (enum) → كود وحدة القياس الجديد
        $unitMap = ['piece' => 'PCS', 'meter' => 'M', 'sqm' => 'M2', 'hour' => 'HR', 'kg' => 'KG', 'liter' => 'L'];

        $items = [
            // معدات — مخزنية
            ['code' => 'PMP-001', 'name' => 'مضخة مسبح 1 حصان', 'type' => 'stock', 'unit' => 'piece', 'cost' => 850, 'price' => 1400, 'stock' => 12, 'reorder' => 3, 'cat' => 'معدات وفلاتر'],
            ['code' => 'PMP-002', 'name' => 'مضخة مسبح 2 حصان', 'type' => 'stock', 'unit' => 'piece', 'cost' => 1250, 'price' => 1950, 'stock' => 8, 'reorder' => 2, 'cat' => 'معدات وفلاتر'],
            ['code' => 'FLT-001', 'name' => 'فلتر رملي 24 بوصة', 'type' => 'stock', 'unit' => 'piece', 'cost' => 950, 'price' => 1600, 'stock' => 10, 'reorder' => 3, 'cat' => 'معدات وفلاتر'],
            ['code' => 'LGT-001', 'name' => 'كشاف مسبح LED', 'type' => 'stock', 'unit' => 'piece', 'cost' => 180, 'price' => 320, 'stock' => 25, 'reorder' => 6, 'cat' => 'معدات وفلاتر'],
            ['code' => 'HTR-001', 'name' => 'سخان مسبح كهربائي', 'type' => 'stock', 'unit' => 'piece', 'cost' => 2600, 'price' => 3900, 'stock' => 4, 'reorder' => 2, 'cat' => 'معدات وفلاتر'],

            // مواد كيميائية
            ['code' => 'CHM-001', 'name' => 'كلور حبيبي', 'type' => 'stock', 'unit' => 'kg', 'cost' => 18, 'price' => 32, 'stock' => 200, 'reorder' => 50, 'cat' => 'مواد كيميائية'],
            ['code' => 'CHM-002', 'name' => 'مانع طحالب', 'type' => 'stock', 'unit' => 'liter', 'cost' => 28, 'price' => 50, 'stock' => 120, 'reorder' => 30, 'cat' => 'مواد كيميائية'],
            ['code' => 'CHM-003', 'name' => 'رافع قلوية pH+', 'type' => 'stock', 'unit' => 'kg', 'cost' => 14, 'price' => 26, 'stock' => 150, 'reorder' => 40, 'cat' => 'مواد كيميائية'],

            // قطع غيار
            ['code' => 'SPR-001', 'name' => 'سلة سكيمر', 'type' => 'stock', 'unit' => 'piece', 'cost' => 35, 'price' => 65, 'stock' => 40, 'reorder' => 10, 'cat' => 'قطع غيار'],
            ['code' => 'SPR-002', 'name' => 'صمام متعدد الاتجاهات', 'type' => 'stock', 'unit' => 'piece', 'cost' => 220, 'price' => 380, 'stock' => 15, 'reorder' => 4, 'cat' => 'قطع غيار'],

            // بالقياس — كمية كسرية
            ['code' => 'MSR-001', 'name' => 'عزل مسابح', 'type' => 'measured', 'unit' => 'sqm', 'cost' => 45, 'price' => 90, 'stock' => 1000, 'reorder' => 200, 'cat' => 'خامات بالقياس'],
            ['code' => 'MSR-002', 'name' => 'بلاط فسيفساء', 'type' => 'measured', 'unit' => 'sqm', 'cost' => 65, 'price' => 130, 'stock' => 800, 'reorder' => 150, 'cat' => 'خامات بالقياس'],
            ['code' => 'MSR-003', 'name' => 'ماسورة PVC 2 بوصة', 'type' => 'measured', 'unit' => 'meter', 'cost' => 9, 'price' => 18, 'stock' => 600, 'reorder' => 120, 'cat' => 'خامات بالقياس'],

            // خدمات — بلا رصيد
            ['code' => 'SRV-001', 'name' => 'زيارة صيانة دورية', 'type' => 'service', 'unit' => 'piece', 'cost' => 0, 'price' => 350, 'stock' => 0, 'reorder' => 0, 'cat' => 'خدمات صيانة'],
            ['code' => 'SRV-002', 'name' => 'تنظيف وغسيل فلتر', 'type' => 'service', 'unit' => 'piece', 'cost' => 0, 'price' => 250, 'stock' => 0, 'reorder' => 0, 'cat' => 'خدمات صيانة'],
            ['code' => 'SRV-003', 'name' => 'ساعة عمل فني', 'type' => 'service', 'unit' => 'hour', 'cost' => 0, 'price' => 120, 'stock' => 0, 'reorder' => 0, 'cat' => 'خدمات صيانة'],
            ['code' => 'SRV-004', 'name' => 'كشف وتشخيص أعطال', 'type' => 'service', 'unit' => 'piece', 'cost' => 0, 'price' => 200, 'stock' => 0, 'reorder' => 0, 'cat' => 'خدمات صيانة'],
        ];

        foreach ($items as $row) {
            Item::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'item_category_id' => $categories[$row['cat']],
                    'department_id' => $pools?->id,
                    'type' => $row['type'],
                    'unit' => $row['unit'],
                    'measure_unit_id' => $units[$unitMap[$row['unit']] ?? 'PCS'] ?? null,
                    'cost' => $row['cost'],
                    'price' => $row['price'],
                    'tax_rate' => (float) config('operations.vat_rate', 15),
                    'stock_qty' => $row['stock'],
                    'reorder_point' => $row['reorder'],
                    'is_active' => true,
                ],
            );
        }

        // حزمة/مشروع: تركيب مسبح كامل — بيعها يخصم مكوّناتها من المخزون
        $bundle = Item::updateOrCreate(
            ['code' => 'PRJ-001'],
            [
                'name' => 'مشروع تركيب مسبح متكامل',
                'item_category_id' => $categories['مشاريع'],
                'department_id' => $pools?->id,
                'type' => 'bundle',
                'unit' => 'piece',
                'measure_unit_id' => $units['PCS'] ?? null,
                'cost' => 0,
                'price' => 18500,
                'tax_rate' => (float) config('operations.vat_rate', 15),
                'stock_qty' => 0,
                'reorder_point' => 0,
                'is_active' => true,
            ],
        );

        $components = [
            'PMP-002' => 1,    // مضخة
            'FLT-001' => 1,    // فلتر
            'LGT-001' => 4,    // كشافات
            'SPR-002' => 1,    // صمام
            'MSR-001' => 60,   // عزل م²
            'MSR-002' => 60,   // بلاط م²
            'MSR-003' => 40,   // مواسير م.ط
            'CHM-001' => 10,   // كلور تشغيل أولي
        ];

        $sync = [];
        foreach ($components as $code => $qty) {
            $id = Item::where('code', $code)->value('id');
            if ($id) {
                $sync[$id] = ['quantity' => $qty];
            }
        }
        $bundle->components()->sync($sync);

        // تكلفة الحزمة = مجموع تكاليف مكوّناتها، وإلا صار ربحها وهميًا.
        $bundle->update([
            'cost' => round(
                $bundle->components()->get()->sum(fn ($c) => (float) $c->cost * (float) $c->pivot->quantity),
                2,
            ),
        ]);
    }
}
