<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر واحد من كشف بنك مستورَد — إيداع أو سحب يُطابَق بحركة نظام.
 */
class BankStatementLine extends Model
{
    protected $fillable = [
        'bank_statement_import_id', 'treasury_id', 'txn_date', 'description', 'amount', 'reference',
        'matched_voucher_id', 'matched_expense_id', 'matched_at', 'matched_by',
    ];

    protected function casts(): array
    {
        return [
            'txn_date' => 'date',
            'amount' => 'decimal:2',
            'matched_at' => 'datetime',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class);
    }

    public function matchedVoucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'matched_voucher_id');
    }

    public function matchedExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'matched_expense_id');
    }

    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    public function isMatched(): bool
    {
        return $this->matched_at !== null;
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('matched_at');
    }
}
