<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one stream of revenue posts, on both sides of the entry.
 *
 * `account_id` is the revenue it credits, `deposit_account_id` the asset it
 * debits when the money is collected. One row per stream, and the row is the
 * whole setting. Resolution — defaults, validity, what happens when the chosen
 * account is deleted — lives in App\Services\Accounting\RevenueAccounts,
 * not here.
 */
class RevenueAccount extends Model
{
    protected $fillable = ['stream', 'account_id', 'deposit_account_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function depositAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deposit_account_id');
    }
}
