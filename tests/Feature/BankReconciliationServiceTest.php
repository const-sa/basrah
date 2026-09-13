<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Treasury;
use App\Models\Voucher;
use App\Services\Accounting\BankReconciliationService;
use App\Services\Accounting\Ledger;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * استيراد كشف بنك CSV ومطابقته التلقائية المبدئية بالسندات والمصروفات.
 */
class BankReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private BankReconciliationService $service;

    private Treasury $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, AccountsSeeder::class]);
        $this->service = app(BankReconciliationService::class);
        $this->bank = Treasury::where('type', 'bank')->firstOrFail();
    }

    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt').'.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, ['التاريخ', 'البيان', 'المبلغ', 'المرجع']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return new UploadedFile($path, 'statement.csv', 'text/csv', null, true);
    }

    public function test_import_reads_every_data_row_and_skips_the_header(): void
    {
        $import = $this->service->import($this->csv([
            ['2026-08-01', 'إيداع', '1000', 'REF1'],
            ['2026-08-02', 'سحب', '-250', 'REF2'],
        ]), $this->bank);

        $this->assertSame(2, $import->lines()->count());
        $this->assertSame(1000.0, (float) $import->lines()->orderBy('txn_date')->first()->amount);
    }

    public function test_a_single_matching_posted_voucher_is_matched_automatically(): void
    {
        Voucher::create([
            'number' => 'RV-BANK-1',
            'type' => 'receipt',
            'voucher_date' => '2026-08-01',
            'amount' => 1000,
            'treasury_id' => $this->bank->id,
            'account_id' => Account::where('code', Ledger::SALES_REVENUE)->value('id'),
            'status' => 'posted',
        ]);

        $import = $this->service->import($this->csv([
            ['2026-08-02', 'تحويل وارد', '1000', 'REF1'],
        ]), $this->bank);

        $line = $import->lines()->first();

        $this->assertTrue($line->isMatched());
        $this->assertNotNull($line->matched_voucher_id);
    }

    public function test_a_matching_posted_expense_is_matched_automatically_for_a_withdrawal(): void
    {
        Expense::create([
            'number' => 'EXP-BANK-1',
            'expense_date' => '2026-08-03',
            'amount' => 500,
            'expense_category_id' => ExpenseCategory::where('code', 'maintenance')->value('id'),
            'treasury_id' => $this->bank->id,
            'payment_method_id' => $this->paymentMethodId('cash'),
            'status' => 'posted',
        ]);

        $import = $this->service->import($this->csv([
            ['2026-08-04', 'سحب', '-500', null],
        ]), $this->bank);

        $line = $import->lines()->first();

        $this->assertTrue($line->isMatched());
        $this->assertNotNull($line->matched_expense_id);
    }

    public function test_multiple_candidates_are_left_for_manual_matching(): void
    {
        Voucher::create([
            'number' => 'RV-BANK-2', 'type' => 'receipt', 'voucher_date' => '2026-08-01', 'amount' => 300,
            'treasury_id' => $this->bank->id, 'account_id' => Account::where('code', Ledger::SALES_REVENUE)->value('id'), 'status' => 'posted',
        ]);
        Voucher::create([
            'number' => 'RV-BANK-3', 'type' => 'receipt', 'voucher_date' => '2026-08-02', 'amount' => 300,
            'treasury_id' => $this->bank->id, 'account_id' => Account::where('code', Ledger::SALES_REVENUE)->value('id'), 'status' => 'posted',
        ]);

        $import = $this->service->import($this->csv([
            ['2026-08-02', 'تحويل وارد', '300', null],
        ]), $this->bank);

        $line = $import->lines()->first();

        $this->assertFalse($line->isMatched());

        $candidate = Voucher::where('number', 'RV-BANK-2')->firstOrFail();
        $this->service->match($line, $candidate);

        $this->assertTrue($line->fresh()->isMatched());
        $this->assertSame($candidate->id, $line->fresh()->matched_voucher_id);

        $this->service->unmatch($line->fresh());
        $this->assertFalse($line->fresh()->isMatched());
    }
}
