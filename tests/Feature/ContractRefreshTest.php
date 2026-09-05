<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\ContractTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\BookingService;
use App\Services\ContractService;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\ContractTemplateSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * إعادة بناء نص المسودة من نموذج العقد بعد تحريره.
 */
class ContractRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class, AccountsSeeder::class, ContractTemplateSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $client = Client::create(['name' => 'خالد المطيري', 'mobile' => '0551234567']);

        $this->booking = app(BookingService::class)->create([
            'unit_id' => $this->chaletLetWhole()->id,
            'client_id' => $client->id,
            'scope' => 'whole',
            'booking_date' => '2026-09-10',
            'period' => 'full_day',
            'status' => 'deposit_paid',
            'guests_count' => 30,
        ]);
    }

    public function test_the_draft_takes_the_edited_template_text(): void
    {
        $contract = app(ContractService::class)->generate($this->booking);
        $template = $contract->template;

        $template->update(['terms' => 'شروط محدَّثة لعميلنا {{client_name}}']);

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/refresh")
            ->assertRedirect();

        $contract->refresh();

        $this->assertStringContainsString('شروط محدَّثة لعميلنا خالد المطيري', $contract->terms);
        $this->assertStringNotContainsString('{{', (string) $contract->terms);
    }

    public function test_the_number_and_date_survive_the_rebuild(): void
    {
        $contract = app(ContractService::class)->generate($this->booking);
        $number = $contract->number;
        $contractDate = $contract->data['contract_date'];

        $contract->template->update(['body' => 'عقد {{contract_number}} بتاريخ {{contract_date}}']);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/refresh");

        $contract->refresh();

        // إعادة البناء تحديث نصٍّ لا إصدار عقد جديد: الرقم وتاريخ الإصدار يبقيان.
        $this->assertSame($number, $contract->number);
        $this->assertSame($contractDate, $contract->data['contract_date']);
        $this->assertStringContainsString($number, $contract->body);
    }

    public function test_a_sent_contract_is_never_rewritten(): void
    {
        $contract = app(ContractService::class)->generate($this->booking);
        $contract->update(['status' => 'sent', 'sent_at' => now()]);

        $frozen = $contract->terms;
        $contract->template->update(['terms' => 'شروط لا يجوز أن تصل عقدًا مُرسلًا']);

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/refresh")
            ->assertRedirect();

        $this->assertSame($frozen, $contract->fresh()->terms);
    }

    public function test_a_signed_contract_is_never_rewritten(): void
    {
        $contract = app(ContractService::class)->generate($this->booking);
        $contract->update(['status' => 'signed', 'signed_at' => now()]);

        $frozen = $contract->terms;
        $contract->template->update(['terms' => 'شروط لا يجوز أن تصل عقدًا موقّعًا']);

        $this->actingAs($this->owner)->post("/admin/contracts/{$contract->id}/refresh");

        $this->assertSame($frozen, $contract->fresh()->terms);
    }

    public function test_the_rebuild_follows_the_default_template_when_it_changes(): void
    {
        $contract = app(ContractService::class)->generate($this->booking);

        $other = ContractTemplate::create([
            'name' => 'نموذج بديل',
            'body' => 'نص بديل للعميل {{client_name}}',
            'terms' => 'شروط النموذج البديل',
            'is_default' => false,
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)
            ->post("/admin/contracts/{$contract->id}/refresh", ['contract_template_id' => $other->id])
            ->assertRedirect();

        $contract->refresh();

        $this->assertSame($other->id, $contract->contract_template_id);
        $this->assertSame('شروط النموذج البديل', $contract->terms);
    }

    public function test_the_screen_shows_the_terms_of_the_template(): void
    {
        $contract = app(ContractService::class)->generate($this->booking);

        $this->actingAs($this->owner)->get("/admin/contracts/{$contract->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/contracts/Show')
                ->where('contract.terms', $contract->terms));
    }
}
