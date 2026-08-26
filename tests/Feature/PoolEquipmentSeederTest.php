<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Item;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\PoolEquipmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قائمة تجهيزات المسابح — بذرة تُشغَّل على قاعدةٍ عاملة، فما فيها من
 * أسعارٍ أدخلها المشغّل لا يجوز أن يمحوه تشغيلٌ ثانٍ.
 */
class PoolEquipmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_lands_in_the_pools_department_ready_to_quote(): void
    {
        $this->seed([DepartmentsSeeder::class, PoolEquipmentSeeder::class]);

        $pools = Department::firstWhere('code', 'POOLS');

        $this->assertSame(13, Item::whereIn('code', [
            'FLT-002', 'PMP-003', 'SND-001', 'FTG-001', 'FTG-002', 'DRN-001', 'LDR-001',
            'LGT-002', 'TRF-001', 'JBX-001', 'VLV-001', 'SKM-001', 'VAC-001',
        ])->count());

        $filter = Item::firstWhere('code', 'FLT-002');

        // The size reads on the invoice line, which prints the name alone.
        $this->assertSame('فلتر رملي هايورد أمريكي 18 بوصة', $filter->name);
        $this->assertSame('18 بوصة', $filter->description);
        $this->assertSame($pools->id, $filter->department_id);
        $this->assertTrue($filter->is_active);

        // No money is invented, and no stock the warehouse never received.
        $this->assertSame(0.0, (float) $filter->price);
        $this->assertSame(0.0, (float) $filter->stock_qty);
    }

    public function test_a_second_run_leaves_a_priced_item_as_the_operator_left_it(): void
    {
        $this->seed([DepartmentsSeeder::class, PoolEquipmentSeeder::class]);

        Item::where('code', 'PMP-003')->update(['price' => 2400, 'cost' => 1600, 'stock_qty' => 3]);

        $this->seed(PoolEquipmentSeeder::class);

        $pump = Item::firstWhere('code', 'PMP-003');

        $this->assertSame(2400.0, (float) $pump->price);
        $this->assertSame(1600.0, (float) $pump->cost);
        $this->assertSame(3.0, (float) $pump->stock_qty);
        $this->assertSame(13, Item::where('code', 'like', '%-00%')->count());
    }

    /**
     * الصنف المؤرشف يعود بالتشغيل، ولا يصطدم كوده بفهرسه الفريد.
     */
    public function test_an_archived_item_comes_back_instead_of_colliding(): void
    {
        $this->seed([DepartmentsSeeder::class, PoolEquipmentSeeder::class]);

        Item::firstWhere('code', 'SKM-001')->delete();

        $this->seed(PoolEquipmentSeeder::class);

        $this->assertNotNull(Item::firstWhere('code', 'SKM-001'));
        $this->assertSame(1, Item::withTrashed()->where('code', 'SKM-001')->count());
    }
}
