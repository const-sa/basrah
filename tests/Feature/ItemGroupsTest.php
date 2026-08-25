<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مجموعات الأصناف: تحديد محفوظ يُملأ به الفاتورة أو عرض السعر دفعةً واحدة.
 */
class ItemGroupsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolesSeeder::class, DepartmentsSeeder::class, UnitsSeeder::class,
            BookingSetupSeeder::class, AccountsSeeder::class, CatalogSeeder::class,
        ]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function makeGroup(string $name, array $itemIds): ItemGroup
    {
        $group = ItemGroup::create(['name' => $name]);

        foreach ($itemIds as $i => $id) {
            $group->lines()->create(['item_id' => $id, 'sort_order' => $i]);
        }

        return $group->fresh('items');
    }

    public function test_a_group_is_saved_with_the_items_chosen_for_it(): void
    {
        $ids = Item::limit(3)->pluck('id')->all();

        $this->actingAs($this->owner)
            ->post('/admin/item-groups', ['name' => 'صيانة دورية', 'item_ids' => $ids])
            ->assertRedirect();

        $group = ItemGroup::firstOrFail();

        $this->assertSame('صيانة دورية', $group->name);
        $this->assertTrue($group->is_active);
        $this->assertEqualsCanonicalizing($ids, $group->items->pluck('id')->all());
    }

    /**
     * المجموعة تحديدٌ لا كمية، فتكرار الصنف فيها لا يعني شيئًا — ويُطرح بدل
     * أن يصطدم بقيد التفرّد فيسقط الحفظ كله على المستخدم.
     */
    public function test_a_repeated_item_is_stored_once(): void
    {
        $id = Item::value('id');

        $this->actingAs($this->owner)
            ->post('/admin/item-groups', ['name' => 'مكرّرة', 'item_ids' => [$id, $id, $id]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ItemGroup::firstOrFail()->items()->count());
    }

    public function test_a_group_with_no_items_is_refused(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/item-groups', ['name' => 'فارغة', 'item_ids' => []])
            ->assertSessionHasErrors('item_ids');

        $this->assertSame(0, ItemGroup::count());
    }

    public function test_editing_replaces_the_selection(): void
    {
        $items = Item::limit(4)->pluck('id')->all();
        $group = $this->makeGroup('قبل', [$items[0], $items[1]]);

        $this->actingAs($this->owner)
            ->put("/admin/item-groups/{$group->id}", [
                'name' => 'بعد',
                'item_ids' => [$items[2], $items[3]],
            ])
            ->assertRedirect();

        $this->assertSame('بعد', $group->fresh()->name);
        $this->assertEqualsCanonicalizing([$items[2], $items[3]], $group->fresh('items')->items->pluck('id')->all());
    }

    public function test_duplicating_copies_the_selection(): void
    {
        $ids = Item::limit(2)->pluck('id')->all();
        $group = $this->makeGroup('الأصل', $ids);

        $this->actingAs($this->owner)
            ->post("/admin/item-groups/{$group->id}/duplicate")
            ->assertRedirect();

        $copy = ItemGroup::where('id', '!=', $group->id)->firstOrFail();

        $this->assertSame('الأصل — نسخة', $copy->name);
        $this->assertEqualsCanonicalizing($ids, $copy->items->pluck('id')->all());
    }

    public function test_the_screen_requires_permission(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
        ]);

        // الكاشير يرى المجموعات ليستعملها في الفاتورة، ولا ينشئها.
        $this->actingAs($cashier)->get('/admin/item-groups')->assertOk();
        $this->actingAs($cashier)
            ->post('/admin/item-groups', ['name' => 'x', 'item_ids' => [Item::value('id')]])
            ->assertForbidden();
    }

    // ── ما يصل إلى الشاشات ───────────────────────────────────

    public function test_the_pos_only_offers_members_of_the_shown_department(): void
    {
        $pools = Department::where('code', 'POOLS')->firstOrFail();

        $inside = Item::where('department_id', $pools->id)->firstOrFail();
        $outside = Item::create([
            'code' => 'OUT-001', 'name' => 'صنف قسم آخر', 'type' => 'service',
            'unit' => 'piece', 'price' => 100, 'cost' => 0, 'tax_rate' => 0,
            'department_id' => Department::where('id', '!=', $pools->id)->value('id'),
            'is_active' => true,
        ]);

        $this->makeGroup('عابرة للأقسام', [$inside->id, $outside->id]);

        // صنف قسمٍ آخر لا تعرفه شاشة الكاشير، فسطره يخرج بلا وحدة ولا سعر
        // يُحرَّر — فيُطرح من المجموعة قبل أن يصل إليها.
        $this->actingAs($this->owner)
            ->get("/admin/pos?department_id={$pools->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/pos/Index')
                ->where('groups.0.name', 'عابرة للأقسام')
                ->where('groups.0.items', fn ($items) => count($items) === 1
                    && $items[0]['id'] === $inside->id)
                ->where('groups.0.skipped_count', 1));
    }

    /**
     * لا يبقى منها شيء لهذا القسم — وزرٌّ لا يضيف شيئًا يُقرأ عطلًا، فلا يُعرض.
     */
    public function test_a_group_with_nothing_usable_is_not_offered(): void
    {
        $pools = Department::where('code', 'POOLS')->firstOrFail();

        $outside = Item::create([
            'code' => 'OUT-002', 'name' => 'خارج القسم', 'type' => 'service',
            'unit' => 'piece', 'price' => 50, 'cost' => 0, 'tax_rate' => 0,
            'department_id' => Department::where('id', '!=', $pools->id)->value('id'),
            'is_active' => true,
        ]);

        $this->makeGroup('كلها خارج القسم', [$outside->id]);

        $this->actingAs($this->owner)
            ->get("/admin/pos?department_id={$pools->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('groups', []));
    }

    public function test_a_stopped_group_is_not_offered(): void
    {
        $group = $this->makeGroup('موقوفة', [Item::value('id')]);
        $group->update(['is_active' => false]);

        $this->actingAs($this->owner)
            ->get('/admin/quotations/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('groups', []));
    }

    /**
     * الصنف الموقوف لا يُباع، فلا يُضاف سطرًا لمجرّد أنه محفوظ في مجموعة.
     */
    public function test_a_stopped_item_is_dropped_from_the_group(): void
    {
        $items = Item::limit(2)->get();
        $this->makeGroup('فيها موقوف', $items->pluck('id')->all());
        $items[0]->update(['is_active' => false]);

        $this->actingAs($this->owner)
            ->get('/admin/quotations/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groups.0.items', fn ($members) => count($members) === 1
                    && $members[0]['id'] === $items[1]->id));
    }

    /**
     * سطر المشتريات يُسعَّر بالتكلفة، فلا بد أن تصل مع أعضاء المجموعة —
     * وإلا دخل السطر بتكلفة undefined ورفضه الخادم.
     */
    public function test_the_purchase_form_receives_member_costs(): void
    {
        $item = Item::where('cost', '>', 0)->firstOrFail();
        $this->makeGroup('مشتريات', [$item->id]);

        $this->actingAs($this->owner)
            ->get('/admin/purchases/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groups.0.items.0.cost', fn ($cost) => (float) $cost === (float) $item->cost));
    }

    /**
     * وعرض السعر لا يعرض التكلفة — سعر البيع وحده ما يخصّ العميل.
     */
    public function test_the_quotation_form_never_receives_member_costs(): void
    {
        $item = Item::where('cost', '>', 0)->firstOrFail();
        $this->makeGroup('عرض سعر', [$item->id]);

        $this->actingAs($this->owner)
            ->get('/admin/quotations/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('groups.0.items.0', fn ($member) => ! collect($member)->has('cost')));
    }
}
