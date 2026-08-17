<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Client;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الأرشيف: ما حُذف من الشاشات يبقى مؤرشفًا حتى يُسترجَع أو يُتلَف عمدًا.
 */
class ArchiveTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class]);

        $this->owner = User::factory()->create([
            'name' => 'مالك النظام',
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    /**
     * الحذف من شاشة العملاء لا يُتلف الصف — يرفعه من الاستعمال ويُبقيه.
     */
    public function test_deleting_from_a_screen_archives_instead_of_erasing(): void
    {
        $client = Client::create(['name' => 'عميل محذوف', 'mobile' => '0551112222', 'is_active' => true]);

        $this->actingAs($this->owner)
            ->delete("/admin/clients/{$client->id}")
            ->assertRedirect();

        $this->assertNull(Client::find($client->id));
        $this->assertNotNull(Client::withTrashed()->find($client->id)?->deleted_at);
    }

    public function test_archive_screen_lists_deleted_records_across_types(): void
    {
        $this->actingAs($this->owner);

        Client::create(['name' => 'عميل محذوف', 'is_active' => true])->delete();
        Supplier::create(['name' => 'مورّد محذوف', 'is_active' => true])->delete();
        Client::create(['name' => 'عميل قائم', 'is_active' => true]);

        $this->get('/admin/archive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/system/Archive')
                ->has('records.data', 2)
                ->where('stats.total', 2)
                ->has('types'),
            );
    }

    public function test_archive_filters_by_type_and_search(): void
    {
        $this->actingAs($this->owner);

        Client::create(['name' => 'عميل محذوف', 'is_active' => true])->delete();
        Supplier::create(['name' => 'مورّد محذوف', 'is_active' => true])->delete();

        $this->get('/admin/archive?type=suppliers')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.name', 'مورّد محذوف'),
            );

        $this->get('/admin/archive?search=عميل')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('records.data', 1)
                ->where('records.data.0.type', 'clients'),
            );
    }

    /**
     * فاعل الحذف يُقرأ من السجل الرقابي — الجدول نفسه لا يحفظه.
     */
    public function test_archive_shows_who_deleted_the_record(): void
    {
        $this->actingAs($this->owner);

        $client = Client::create(['name' => 'عميل محذوف', 'is_active' => true]);
        $this->delete("/admin/clients/{$client->id}");

        $this->get('/admin/archive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('records.data.0.deleted_by', 'مالك النظام'));
    }

    public function test_restoring_returns_the_record_to_service(): void
    {
        $this->actingAs($this->owner);

        $city = City::create(['name' => 'البصرة', 'is_active' => true]);
        $city->delete();

        $this->post("/admin/archive/cities/{$city->id}/restore")->assertRedirect();

        $this->assertNotNull(City::find($city->id));
    }

    public function test_force_delete_erases_the_record_for_good(): void
    {
        $this->actingAs($this->owner);

        $city = City::create(['name' => 'البصرة', 'is_active' => true]);
        $city->delete();

        $this->delete("/admin/archive/cities/{$city->id}")->assertRedirect();

        $this->assertNull(City::withTrashed()->find($city->id));
    }

    /**
     * السجل القائم ليس في الأرشيف، فلا يُتلف من مساره.
     */
    public function test_a_live_record_cannot_be_reached_through_the_archive(): void
    {
        $this->actingAs($this->owner);

        $city = City::create(['name' => 'البصرة', 'is_active' => true]);

        $this->delete("/admin/archive/cities/{$city->id}")->assertNotFound();
        $this->post("/admin/archive/cities/{$city->id}/restore")->assertNotFound();

        $this->assertNotNull(City::find($city->id));
    }

    /**
     * المفتاح في المسار نوعٌ مسجَّل لا اسم صنف — فلا يصير أي نموذج هدفًا.
     */
    public function test_unknown_type_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/archive/App%5CModels%5CSetting/1/restore')
            ->assertNotFound();
    }

    /**
     * الفهرس الفريد لا يفرّق بين حيٍّ ومؤرشف — فالرسالة هي التي تدلّ على المخرج.
     */
    public function test_a_unique_value_held_by_an_archived_record_points_to_the_archive(): void
    {
        $this->actingAs($this->owner);

        $city = City::create(['name' => 'البصرة', 'is_active' => true]);
        $city->delete();

        $this->post('/admin/cities', ['name' => 'البصرة'])
            ->assertSessionHasErrors(['name' => 'القيمة مستعملة في سجل مؤرشف — استرجعه من الأرشيف أو احذفه نهائيًا ثم أعد المحاولة.']);

        // والمزاحم الحيّ يبقى على رسالته الأصلية.
        City::create(['name' => 'بغداد', 'is_active' => true]);

        $this->post('/admin/cities', ['name' => 'بغداد'])
            ->assertSessionHasErrors('name');

        $this->assertStringNotContainsString(
            'الأرشيف',
            (string) session('errors')->first('name'),
        );
    }

    public function test_archive_is_guarded_by_permissions(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $city = City::create(['name' => 'البصرة', 'is_active' => true]);
        $city->delete();

        $this->actingAs($cashier)->get('/admin/archive')->assertForbidden();
        $this->actingAs($cashier)->post("/admin/archive/cities/{$city->id}/restore")->assertForbidden();
        $this->actingAs($cashier)->delete("/admin/archive/cities/{$city->id}")->assertForbidden();
    }
}
