<?php

namespace App\Services\Accounting;

use App\Models\Expense;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * دورة حياة المصروف: يُسجَّل مسوّدةً، ثم يُرحَّل فيولّد قيده، أو يُلغى
 * فيُعكس القيد.
 *
 * القيد بسطرين: بند المصروف مدين (تحمّلته المؤسسة) والخزينة دائنة (خرج
 * منها المال) — وكلاهما على مركز تكلفة الوحدة إن حُدِّدت، وبها وحدها
 * تُعرف ربحية القاعة من الشاليه.
 */
class ExpenseService
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId = null): Expense
    {
        return Expense::create([
            ...$data,
            'number' => $this->nextNumber(),
            'status' => 'draft',
            'created_by' => $userId,
        ]);
    }

    /**
     * ترحيل المصروف إلى الدفاتر.
     */
    public function post(Expense $expense, ?int $userId = null): Expense
    {
        if ($expense->isPosted()) {
            throw new RuntimeException("المصروف {$expense->number} مرحَّل مسبقًا.");
        }

        if ($expense->status === 'cancelled') {
            throw new RuntimeException('المصروف ملغى فلا يُرحَّل.');
        }

        return DB::transaction(function () use ($expense, $userId) {
            $expense->loadMissing(['category.account', 'treasury.account']);

            $expenseAccount = $expense->category?->account_id
                ?? throw new RuntimeException('نوع المصروف غير مرتبط بحساب في شجرة الحسابات.');

            $treasuryAccount = $expense->treasury?->account_id
                ?? throw new RuntimeException('الخزينة غير مرتبطة بحساب في شجرة الحسابات.');

            $amount = (float) $expense->amount;
            $costCenter = $expense->cost_center_id;

            $entry = $this->ledger->post(
                $expense->expense_date->toDateString(),
                $this->description($expense),
                [
                    ['account' => $expenseAccount, 'debit' => $amount, 'cost_center_id' => $costCenter],
                    ['account' => $treasuryAccount, 'credit' => $amount, 'cost_center_id' => $costCenter],
                ],
                'expense',
                $expense,
                $userId,
            );

            $expense->update([
                'status' => 'posted',
                'journal_entry_id' => $entry->id,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $expense->fresh();
        });
    }

    /**
     * الإلغاء يعكس القيد ولا يمحوه — الدفتر يحفظ أن الحركة وقعت ثم رُدَّت.
     */
    public function cancel(Expense $expense, ?string $reason = null, ?int $userId = null): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $userId) {
            if ($expense->isPosted() && $expense->entry) {
                $this->ledger->reverse($expense->entry, $reason, $userId);
            }

            $expense->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $expense->fresh();
        });
    }

    /**
     * وصف القيد كما يقرأه المحاسب في دفتر اليومية.
     */
    private function description(Expense $expense): string
    {
        $parts = array_filter([
            'مصروف '.($expense->category?->name ?? ''),
            $expense->costCenter?->name,
            $expense->description,
        ]);

        return mb_substr(implode(' — ', $parts), 0, 255);
    }

    /**
     * الترقيم متسلسل داخل السنة: EXP-2026-1.
     */
    private function nextNumber(): string
    {
        $prefix = Expense::PREFIX.now()->year.'-';

        $last = Expense::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $sequence = $last ? ((int) str_replace($prefix, '', $last)) + 1 : 1;

        return $prefix.$sequence;
    }
}
