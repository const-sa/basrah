<?php

namespace App\Services\Accounting;

use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Expense;
use App\Models\Treasury;
use App\Models\Voucher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * التسوية البنكية: استيراد كشف بنك CSV ومطابقته بما هو مرحَّل فعلاً في
 * النظام (سندات ومصروفات على نفس الخزينة).
 *
 * لا مكتبة إكسل في المشروع — كل استيراد/تصدير هنا CSV خام بـ fgetcsv، على
 * نفس نهج RevenuesController::export. الأعمدة ثابتة: تاريخ، بيان، مبلغ
 * (موجب إيداع/سالب سحب)، مرجع اختياري.
 */
class BankReconciliationService
{
    /** نافذة الأيام حول تاريخ حركة الكشف يُبحث فيها عن مرشّح مطابق. */
    private const MATCH_WINDOW_DAYS = 3;

    public function import(UploadedFile $file, Treasury $treasury, ?int $userId = null): BankStatementImport
    {
        $rows = $this->parse($file);

        if ($rows === []) {
            throw new RuntimeException('الملف لا يحتوي حركات يمكن قراءتها.');
        }

        return DB::transaction(function () use ($rows, $file, $treasury, $userId) {
            $import = BankStatementImport::create([
                'treasury_id' => $treasury->id,
                'original_filename' => $file->getClientOriginalName(),
                'imported_by' => $userId,
            ]);

            foreach ($rows as $row) {
                BankStatementLine::create([
                    'bank_statement_import_id' => $import->id,
                    'treasury_id' => $treasury->id,
                    ...$row,
                ]);
            }

            $this->autoMatch($import);

            return $import;
        });
    }

    /**
     * مطابقة تلقائية مبدئية: مرشّح وحيد بنفس المبلغ ضمن نافذة الأيام على
     * نفس الخزينة يُطابَق تلقائيًا؛ تعدّد المرشّحين يُترك للمستخدم.
     */
    public function autoMatch(BankStatementImport $import): int
    {
        $matched = 0;

        foreach ($import->lines()->unmatched()->get() as $line) {
            if ($this->tryAutoMatch($line)) {
                $matched++;
            }
        }

        return $matched;
    }

    public function match(BankStatementLine $line, Voucher|Expense $target, ?int $userId = null): void
    {
        $target instanceof Voucher ? $this->matchVoucher($line, $target, $userId) : $this->matchExpense($line, $target, $userId);
    }

    public function unmatch(BankStatementLine $line): void
    {
        $line->update(['matched_voucher_id' => null, 'matched_expense_id' => null, 'matched_at' => null, 'matched_by' => null]);
    }

    private function tryAutoMatch(BankStatementLine $line): bool
    {
        $window = [
            $line->txn_date->copy()->subDays(self::MATCH_WINDOW_DAYS)->toDateString(),
            $line->txn_date->copy()->addDays(self::MATCH_WINDOW_DAYS)->toDateString(),
        ];
        $amount = abs((float) $line->amount);

        $matchedVoucherIds = BankStatementLine::whereNotNull('matched_voucher_id')->pluck('matched_voucher_id');
        $matchedExpenseIds = BankStatementLine::whereNotNull('matched_expense_id')->pluck('matched_expense_id');

        $vouchers = Voucher::where('treasury_id', $line->treasury_id)
            ->where('status', 'posted')
            ->where('amount', $amount)
            ->whereBetween('voucher_date', $window)
            ->whereNotIn('id', $matchedVoucherIds)
            ->when($line->amount > 0, fn ($q) => $q->where('type', 'receipt'))
            ->when($line->amount < 0, fn ($q) => $q->whereIn('type', ['payment', 'expense']))
            ->get();

        $expenses = $line->amount < 0
            ? Expense::where('treasury_id', $line->treasury_id)
                ->posted()
                ->where('amount', $amount)
                ->whereBetween('expense_date', $window)
                ->whereNotIn('id', $matchedExpenseIds)
                ->get()
            : collect();

        if ($vouchers->count() + $expenses->count() !== 1) {
            return false;
        }

        $vouchers->isNotEmpty() ? $this->matchVoucher($line, $vouchers->first()) : $this->matchExpense($line, $expenses->first());

        return true;
    }

    private function matchVoucher(BankStatementLine $line, Voucher $voucher, ?int $userId = null): void
    {
        $line->update(['matched_voucher_id' => $voucher->id, 'matched_expense_id' => null, 'matched_at' => now(), 'matched_by' => $userId]);
    }

    private function matchExpense(BankStatementLine $line, Expense $expense, ?int $userId = null): void
    {
        $line->update(['matched_expense_id' => $expense->id, 'matched_voucher_id' => null, 'matched_at' => now(), 'matched_by' => $userId]);
    }

    /**
     * @return list<array{txn_date: string, description: string|null, amount: float, reference: string|null}>
     */
    private function parse(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');

        if (! $handle) {
            throw new RuntimeException('تعذّرت قراءة الملف.');
        }

        $rows = [];
        $firstRow = true;

        while (($cols = fgetcsv($handle)) !== false) {
            if ($firstRow) {
                $firstRow = false;

                // صف العناوين يُتجاوَز — أول عمود فيه ليس تاريخًا صالحًا.
                if (! $this->parseDate($cols[0] ?? null)) {
                    continue;
                }
            }

            $date = $this->parseDate($cols[0] ?? null);

            if ($date === null || count($cols) < 3) {
                continue;
            }

            $rows[] = [
                'txn_date' => $date,
                'description' => trim((string) ($cols[1] ?? '')) ?: null,
                'amount' => round((float) str_replace([',', ' '], '', (string) ($cols[2] ?? '0')), 2),
                'reference' => trim((string) ($cols[3] ?? '')) ?: null,
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
