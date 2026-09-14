<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\Treasury;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherAttachment;
use App\Services\Accounting\Ledger;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Voucher attachments: filing on create and after, deleting, and the limits.
 */
class VoucherAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        Storage::fake('public');
    }

    public function test_a_voucher_is_created_with_its_supporting_documents(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/vouchers', [
                ...$this->voucherPayload(),
                'attachments' => [
                    UploadedFile::fake()->image('transfer.png'),
                    UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf'),
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $voucher = Voucher::firstOrFail();

        $this->assertCount(2, $voucher->attachments);
        $this->assertSame(['transfer.png', 'invoice.pdf'], $voucher->attachments->pluck('original_name')->all());
        $this->assertSame($this->owner->id, $voucher->attachments->first()->uploaded_by);

        foreach ($voucher->attachments as $attachment) {
            Storage::disk('public')->assertExists($attachment->path);
        }
    }

    public function test_a_voucher_saves_without_any_attachment(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/vouchers', $this->voucherPayload())
            ->assertSessionHas('success');

        $this->assertSame(0, VoucherAttachment::count());
    }

    public function test_an_executable_file_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/vouchers', [
                ...$this->voucherPayload(),
                'attachments' => [UploadedFile::fake()->create('shell.php', 10, 'text/x-php')],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->assertSame(0, Voucher::count());
    }

    public function test_more_than_five_files_in_one_request_are_rejected(): void
    {
        $files = array_map(fn (int $i) => UploadedFile::fake()->image("doc-{$i}.png"), range(1, 6));

        $this->actingAs($this->owner)
            ->post('/admin/accounting/vouchers', [...$this->voucherPayload(), 'attachments' => $files])
            ->assertSessionHasErrors('attachments');
    }

    public function test_a_document_is_attached_to_a_posted_voucher(): void
    {
        $voucher = $this->voucher('posted');

        $this->actingAs($this->owner)
            ->post("/admin/accounting/vouchers/{$voucher->id}/attachments", [
                'attachments' => [UploadedFile::fake()->create('transfer.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $attachment = $voucher->attachments()->firstOrFail();

        $this->assertSame('transfer.pdf', $attachment->original_name);
        Storage::disk('public')->assertExists($attachment->path);
    }

    public function test_a_cancelled_voucher_refuses_new_attachments(): void
    {
        $voucher = $this->voucher('cancelled');

        $this->actingAs($this->owner)
            ->post("/admin/accounting/vouchers/{$voucher->id}/attachments", [
                'attachments' => [UploadedFile::fake()->image('transfer.png')],
            ])
            ->assertSessionHas('warning');

        $this->assertSame(0, $voucher->attachments()->count());
    }

    public function test_deleting_an_attachment_removes_the_row_and_the_file(): void
    {
        $voucher = $this->voucher();
        $attachment = $this->attach($voucher);

        $this->actingAs($this->owner)
            ->delete("/admin/accounting/vouchers/{$voucher->id}/attachments/{$attachment->id}")
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('voucher_attachments', ['id' => $attachment->id]);
        Storage::disk('public')->assertMissing($attachment->path);
    }

    public function test_an_attachment_cannot_be_deleted_through_another_voucher(): void
    {
        $attachment = $this->attach($this->voucher());
        $other = $this->voucher();

        $this->actingAs($this->owner)
            ->delete("/admin/accounting/vouchers/{$other->id}/attachments/{$attachment->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('voucher_attachments', ['id' => $attachment->id]);
    }

    public function test_the_index_exposes_each_attachment_with_its_link(): void
    {
        $attachment = $this->attach($this->voucher());

        $this->actingAs($this->owner)
            ->get('/admin/accounting/vouchers')
            ->assertInertia(fn ($page) => $page
                ->where('vouchers.data.0.attachments.0.name', 'transfer.png')
                ->where('vouchers.data.0.attachments.0.url', asset('storage/'.$attachment->path))
                ->where('vouchers.data.0.attachments.0.is_image', true));
    }

    public function test_a_user_without_the_edit_permission_cannot_attach(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $voucher = $this->voucher();

        $this->actingAs($cashier)
            ->post("/admin/accounting/vouchers/{$voucher->id}/attachments", [
                'attachments' => [UploadedFile::fake()->image('transfer.png')],
            ])
            ->assertForbidden();
    }

    /**
     * The fields a receipt voucher request requires.
     *
     * @return array<string, mixed>
     */
    private function voucherPayload(): array
    {
        return [
            'type' => 'receipt',
            'voucher_date' => '2026-08-16',
            'amount' => 500,
            'treasury_id' => Treasury::where('type', 'cash')->value('id'),
            'account_id' => Account::where('code', Ledger::SALES_REVENUE)->value('id'),
            'payment_method_id' => $this->paymentMethodId(),
            'post_now' => false,
        ];
    }

    private function voucher(string $status = 'draft'): Voucher
    {
        return Voucher::create([
            'number' => 'RV-ATT-'.uniqid(),
            'type' => 'receipt',
            'voucher_date' => '2026-08-16',
            'amount' => 500,
            'treasury_id' => Treasury::where('type', 'cash')->value('id'),
            'account_id' => Account::where('code', Ledger::SALES_REVENUE)->value('id'),
            'payment_method_id' => $this->paymentMethodId(),
            'status' => $status,
        ]);
    }

    private function attach(Voucher $voucher): VoucherAttachment
    {
        $this->actingAs($this->owner)
            ->post("/admin/accounting/vouchers/{$voucher->id}/attachments", [
                'attachments' => [UploadedFile::fake()->image('transfer.png')],
            ]);

        return $voucher->attachments()->firstOrFail();
    }
}
