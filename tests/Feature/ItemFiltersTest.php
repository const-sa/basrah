<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فلاتر شاشة الأصناف والمخزون: القائمة المربوطة بقيمةٍ لا خيار لها تظهر
 * صندوقًا بلا عنوان، فما لم يُختر يصل معلنًا فارغًا لا غائبًا.
 */
class ItemFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class, AccountsSeeder::class, CatalogSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_an_unchosen_filter_reaches_the_items_screen_as_null_not_missing(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/items')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/items/Index')
                ->where('filters.type', null)
                ->where('filters.category_id', null)
                ->where('filters.department_id', null)
                ->where('filters.search', null)
                // The tick is a boolean either way — never a missing key.
                ->where('filters.low_stock', false)
                ->etc(),
            );
    }

    public function test_a_chosen_filter_comes_back_in_the_type_its_control_reads(): void
    {
        $pools = Department::firstWhere('code', 'POOLS');
        $category = ItemCategory::firstWhere('name', 'معدات وفلاتر');

        $this->actingAs($this->owner)
            ->get("/admin/items?type=stock&department_id={$pools->id}&category_id={$category->id}&low_stock=1")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.type', 'stock')
                ->where('filters.department_id', $pools->id)
                ->where('filters.category_id', $category->id)
                ->where('filters.low_stock', true)
                ->etc(),
            );

        // A filter cleared on the page returns as an empty string, which selects
        // no more than a missing key does.
        $this->actingAs($this->owner)
            ->get('/admin/items?type=&department_id=&low_stock=false')
            ->assertInertia(fn ($page) => $page
                ->where('filters.type', null)
                ->where('filters.department_id', null)
                ->where('filters.low_stock', false)
                ->etc(),
            );
    }

    public function test_the_movements_screen_states_its_two_filters_the_same_way(): void
    {
        $item = Item::firstWhere('code', 'PMP-001');

        $this->actingAs($this->owner)
            ->get('/admin/inventory/movements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/items/Movements')
                ->where('filters.item_id', null)
                ->where('filters.type', null)
                ->etc(),
            );

        $this->actingAs($this->owner)
            ->get("/admin/inventory/movements?item_id={$item->id}&type=in")
            ->assertInertia(fn ($page) => $page
                ->where('filters.item_id', $item->id)
                ->where('filters.type', 'in')
                ->etc(),
            );
    }
}
