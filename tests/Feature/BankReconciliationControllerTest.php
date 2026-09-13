<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Role;
use App\Models\Treasury;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Accounting\Ledger;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * شاشة التسوية البنكية: الاستيراد والمطابقة عبر الواجهة.
 */
class BankReconciliationControllerTest extends TestCase
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
    }

    public function test_importing_a_statement_matches_a_posted_voucher_automatically(): void
    {
        $bank = Treasury::where('type', 'bank')->firstOrFail();

        Voucher::create([
            'number' => 'RV-CTRL-1', 'type' => 'receipt', 'voucher_date' => '2026-08-10',
            'amount' => 750, 'treasury_id' => $bank->id,
            'account_id' => Account::where('code', Ledger::SALES_REVENUE)->value('id'),
            'status' => 'posted',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ['التاريخ', 'البيان', 'المبلغ', 'المرجع']);
        fputcsv($handle, ['2026-08-11', 'تحويل وارد', '750', 'REF']);
        fclose($handle);

        $this->actingAs($this->owner)
            ->post('/admin/accounting/bank-reconciliation/import', [
                'treasury_id' => $bank->id,
                'file' => new UploadedFile($path, 'statement.csv', 'text/csv', null, true),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('bank_statement_lines', ['amount' => 750]);
        $this->assertDatabaseMissing('bank_statement_lines', ['matched_at' => null]);
    }

    public function test_a_cashier_without_the_permission_is_forbidden(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/accounting/bank-reconciliation')->assertForbidden();
    }
}
