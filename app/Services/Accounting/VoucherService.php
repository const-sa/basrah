<?php

namespace App\Services\Accounting;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ترحيل سندات القبض والصرف والمصروفات إلى الدفاتر.
 */
class VoucherService
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * إنشاء سند كمسوّدة.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $userId = null): Voucher
    {
        return Voucher::create([
            ...$data,
            'number' => $this->nextNumber($data['type']),
            'status' => 'draft',
            'created_by' => $userId,
        ]);
    }

    /**
     * ترحيل السند وتوليد قيده.
     */
    public function post(Voucher $voucher, ?int $userId = null): Voucher
    {
        if ($voucher->isPosted()) {
            throw new RuntimeException("السند {$voucher->number} مرحَّل مسبقًا.");
        }

        if ($voucher->status === 'cancelled') {
            throw new RuntimeException('السند ملغي فلا يُرحَّل.');
        }

        return DB::transaction(function () use ($voucher, $userId) {
            $voucher->loadMissing(['treasury.account', 'account']);

            $treasuryAccount = $voucher->treasury?->account_id
                ?? throw new RuntimeException('الخزينة غير مرتبطة بحساب في شجرة الحسابات.');

            $counterAccount = $voucher->account_id
                ?? throw new RuntimeException('لم يُحدَّد الحساب المقابل للسند.');

            $amount = (float) $voucher->amount;
            $costCenter = $voucher->cost_center_id;

            // القبض يزيد الخزينة، والصرف والمصروف ينقصانها.
            $lines = $voucher->increasesTreasury()
                ? [
                    ['account' => $treasuryAccount, 'debit' => $amount, 'cost_center_id' => $costCenter],
                    ['account' => $counterAccount, 'credit' => $amount, 'cost_center_id' => $costCenter],
                ]
                : [
                    ['account' => $counterAccount, 'debit' => $amount, 'cost_center_id' => $costCenter],
                    ['account' => $treasuryAccount, 'credit' => $amount, 'cost_center_id' => $costCenter],
                ];

            $entry = $this->ledger->post(
                $voucher->voucher_date->toDateString(),
                $voucher->typeLabel().' '.$voucher->number.($voucher->description ? " — {$voucher->description}" : ''),
                $lines,
                $voucher->type === 'expense' ? 'expense' : 'voucher',
                $voucher,
                $userId,
            );

            $voucher->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            // سند القبض المربوط بفاتورة يرفع مسدَّدها — الترحيل هو لحظة
            // التحصيل الفعلي، فلا تُحتسب المسوّدة سدادًا.
            if ($voucher->settlesSale()) {
                $voucher->sale?->addSettlement($amount);
            }

            return $voucher->fresh();
        });
    }

    /**
     * إلغاء سند: المرحَّل يُعكس قيده والمسوّدة تُلغى مباشرة.
     */
    public function cancel(Voucher $voucher, ?string $reason = null, ?int $userId = null): Voucher
    {
        return DB::transaction(function () use ($voucher, $reason, $userId) {
            $wasPosted = $voucher->isPosted();

            if ($wasPosted && $voucher->entry) {
                $this->ledger->reverse($voucher->entry, $reason, $userId);
            }

            // ما لم يُرحَّل لم يُحتسب سدادًا، فلا يُخصم عند الإلغاء.
            if ($wasPosted && $voucher->settlesSale()) {
                $voucher->sale?->reverseSettlement((float) $voucher->amount);
            }

            $voucher->update(['status' => 'cancelled']);

            return $voucher->fresh();
        });
    }

    private function nextNumber(string $type): string
    {
        $prefix = match ($type) {
            'receipt' => 'RV-',
            'payment' => 'PV-',
            default => 'EX-',
        }.now()->year.'-';

        $last = Voucher::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
