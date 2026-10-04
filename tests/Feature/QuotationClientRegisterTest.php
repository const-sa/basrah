<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Support\ClientType;
use Database\Seeders\DepartmentsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عرض السعر يُقدَّم لعميل القسم الذي يبيع.
 *
 * العرض يصدر من قسمٍ بائع — المسابح اليوم — فسجلّه سجلّ عملاء ذلك القسم.
 * وعميل قاعةٍ في قائمة عرض معدات مسبح اختيارٌ خاطئ ينتظر أن يُفعَل.
 */
class QuotationClientRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, DepartmentsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_only_pool_clients_are_offered(): void
    {
        $this->client('عميل مسبح', ClientType::POOL);
        $this->client('عميل قاعة', ClientType::HALL);
        $this->client('عميل شاليه', ClientType::CHALET);

        $names = $this->offeredClients();

        $this->assertContains('عميل مسبح', $names);
        $this->assertNotContains('عميل قاعة', $names);
        $this->assertNotContains('عميل شاليه', $names);
    }

    /** العميل النقدي يبقى: البيع المباشر يُحمَّل عليه. */
    public function test_the_walk_in_client_stays_on_the_list(): void
    {
        $walkIn = Client::walkIn();

        $this->assertContains($walkIn->name, $this->offeredClients());
    }

    /** وشاشة التعديل تقرأ السجلّ نفسه، فلا تختلف عن شاشة الإنشاء. */
    public function test_the_edit_screen_reads_the_same_register(): void
    {
        $this->client('عميل قاعة', ClientType::HALL);

        $this->assertNotContains('عميل قاعة', $this->offeredClients());
    }

    /**
     * مربّع البحث هو ما يختار منه الموظف فعلًا — لا قائمة الـprops — فالتصفية
     * تلزمه هو. وهذا ما فات التصفية الأولى فظلّ الدليل كلّه يظهر.
     */
    public function test_the_search_box_answers_from_the_pool_register_only(): void
    {
        $this->client('عميل مسبح', ClientType::POOL);
        $this->client('عميل قاعة', ClientType::HALL);

        $names = collect(
            $this->actingAs($this->owner)
                ->getJson('/admin/api/search?type=clients&register=pool')
                ->assertOk()
                ->json('data')
        )->pluck('name');

        $this->assertContains('عميل مسبح', $names);
        $this->assertNotContains('عميل قاعة', $names);
    }

    /** وبلا المعامل يبقى البحث في الدليل كلّه، فلا تنكسر شاشة تعتمد عليه. */
    public function test_the_search_box_without_a_register_answers_from_the_whole_directory(): void
    {
        $this->client('عميل مسبح', ClientType::POOL);
        $this->client('عميل قاعة', ClientType::HALL);

        $names = collect(
            $this->actingAs($this->owner)
                ->getJson('/admin/api/search?type=clients')
                ->assertOk()
                ->json('data')
        )->pluck('name');

        $this->assertContains('عميل مسبح', $names);
        $this->assertContains('عميل قاعة', $names);
    }

    /** الشاشة تُسلَّم السجلّ لتبني به رابط بحثها. */
    public function test_the_screen_is_handed_the_register_it_must_search_in(): void
    {
        $response = $this->actingAs($this->owner)->get('/admin/quotations/create');

        $this->assertSame(['pool'], $response->viewData('page')['props']['client_register']);
    }

    /** @return list<string> */
    private function offeredClients(): array
    {
        $response = $this->actingAs($this->owner)->get('/admin/quotations/create');

        $response->assertOk();

        return collect($response->viewData('page')['props']['clients'])
            ->pluck('name')
            ->all();
    }

    private function client(string $name, string $type): Client
    {
        return Client::create([
            'name' => $name,
            'mobile' => '05'.random_int(10000000, 99999999),
            'type' => $type,
            'is_active' => true,
        ]);
    }
}
