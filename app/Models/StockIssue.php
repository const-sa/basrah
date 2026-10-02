<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * إذن صرف — مواد تخرج من المخزن لتُستهلك في عمل القسم (كلور ومواد تعقيم
 * مع فني الصيانة)، فتنقص من الرصيد وتُحمَّل على المصروف بتكلفتها.
 */
class StockIssue extends Model
{
    public const STATUSES = [
        'posted' => 'مُعتمد',
        'cancelled' => 'ملغى',
    ];

    protected $fillable = [
        'number', 'issue_date', 'department_id', 'employee_id', 'recipient_name', 'contract_id',
        'expense_account_id', 'total_cost', 'status', 'journal_entry_id', 'notes', 'user_id',
        'cancelled_at', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'total_cost' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockIssueItem::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** من استلم المواد: الموظف المسجّل، وإلا الاسم المكتوب. */
    public function recipientLabel(): ?string
    {
        return $this->employee?->name ?? $this->recipient_name;
    }
}
