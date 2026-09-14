<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Role;
use App\Models\Treasury;
use App\Models\User;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The paper behind an expense: filed on record, replaced, deleted, and the
 * limits on what may be filed at all.
 */
class ExpenseAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, AccountsSeeder::class]);

        $this->owner = User::factory()->create([
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->treasury = Treasury::where('is_active', true)->firstOrFail();

        Storage::fake('public');
    }

    public function test_an_expense_is_recorded_with_its_invoice(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/expenses', [
                ...$this->payload(),
                'attachment' => UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $expense = Expense::firstOrFail();

        $this->assertNotNull($expense->attachment_path);
        Storage::disk('public')->assertExists($expense->attachment_path);
    }

    public function test_an_expense_is_recorded_without_any_paper(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/expenses', $this->payload())
            ->assertSessionHas('success');

        $this->assertNull(Expense::firstOrFail()->attachment_path);
    }

    public function test_an_executable_file_is_rejected_and_nothing_is_recorded(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/expenses', [
                ...$this->payload(),
                'attachment' => UploadedFile::fake()->create('shell.php', 10, 'text/x-php'),
            ])
            ->assertSessionHasErrors('attachment');

        $this->assertSame(0, Expense::count());
    }

    public function test_a_file_over_five_megabytes_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/accounting/expenses', [
                ...$this->payload(),
                'attachment' => UploadedFile::fake()->create('scan.pdf', 6000, 'application/pdf'),
            ])
            ->assertSessionHasErrors('attachment');
    }

    public function test_a_posted_expense_still_accepts_the_invoice_that_arrived_later(): void
    {
        $expense = $this->expense('posted');

        $this->actingAs($this->owner)
            ->post("/admin/accounting/expenses/{$expense->id}/attachment", [
                'attachment' => UploadedFile::fake()->image('meter.png'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Storage::disk('public')->assertExists($expense->refresh()->attachment_path);
    }

    public function test_a_new_paper_replaces_the_old_one_on_the_disk(): void
    {
        $expense = $this->expense();
        $first = $this->attach($expense)->attachment_path;

        $this->actingAs($this->owner)
            ->post("/admin/accounting/expenses/{$expense->id}/attachment", [
                'attachment' => UploadedFile::fake()->create('corrected.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHas('success');

        $second = $expense->refresh()->attachment_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_a_cancelled_expense_refuses_a_new_paper(): void
    {
        $expense = $this->expense('cancelled');

        $this->actingAs($this->owner)
            ->post("/admin/accounting/expenses/{$expense->id}/attachment", [
                'attachment' => UploadedFile::fake()->image('meter.png'),
            ])
            ->assertSessionHas('warning');

        $this->assertNull($expense->refresh()->attachment_path);
    }

    public function test_deleting_the_attachment_clears_the_column_and_the_file(): void
    {
        $expense = $this->expense();
        $path = $this->attach($expense)->attachment_path;

        $this->actingAs($this->owner)
            ->delete("/admin/accounting/expenses/{$expense->id}/attachment")
            ->assertSessionHas('success');

        $this->assertNull($expense->refresh()->attachment_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_editing_a_draft_without_a_file_keeps_the_paper_already_filed(): void
    {
        $expense = $this->expense();
        $path = $this->attach($expense)->attachment_path;

        $this->actingAs($this->owner)
            ->put("/admin/accounting/expenses/{$expense->id}", $this->payload(['amount' => 300, 'post_now' => false]))
            ->assertSessionHas('success');

        $this->assertSame($path, $expense->refresh()->attachment_path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_index_exposes_the_link_to_the_paper(): void
    {
        $expense = $this->expense();
        $path = $this->attach($expense)->attachment_path;

        $this->actingAs($this->owner)
            ->get('/admin/accounting/expenses?from=2026-08-01&to=2026-08-31')
            ->assertInertia(fn ($page) => $page
                ->where('expenses.data.0.attachment_url', asset('storage/'.$path)));
    }

    public function test_a_user_outside_the_units_cannot_attach(): void
    {
        $expense = $this->expense();

        $stranger = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => false,
        ]);

        $this->actingAs($stranger)
            ->post("/admin/accounting/expenses/{$expense->id}/attachment", [
                'attachment' => UploadedFile::fake()->image('meter.png'),
            ])
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'expense_date' => '2026-08-16',
            'amount' => 250,
            'expense_category_id' => ExpenseCategory::where('code', 'electricity')->value('id'),
            'treasury_id' => $this->treasury->id,
            'payment_method_id' => $this->paymentMethodId(),
            'description' => 'فاتورة كهرباء أغسطس',
            'post_now' => false,
            ...$overrides,
        ];
    }

    private function expense(string $status = 'draft'): Expense
    {
        return Expense::create([
            ...collect($this->payload())->except('post_now')->all(),
            'number' => Expense::PREFIX.uniqid(),
            'status' => $status,
        ]);
    }

    private function attach(Expense $expense): Expense
    {
        $this->actingAs($this->owner)
            ->post("/admin/accounting/expenses/{$expense->id}/attachment", [
                'attachment' => UploadedFile::fake()->image('meter.png'),
            ]);

        return $expense->refresh();
    }
}
