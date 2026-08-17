<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفعة على حجز (عربون، دفعة، استرداد).
 */
class BookingPayment extends Model
{
    public const TYPES = [
        'deposit' => 'عربون',
        'payment' => 'دفعة',
        'refund' => 'استرداد',
    ];

    public const METHODS = [
        'cash' => 'نقدًا',
        'transfer' => 'تحويل بنكي',
        'card' => 'شبكة',
        'online' => 'دفع إلكتروني',
    ];

    protected $fillable = [
        'booking_id',
        'received_by',
        'type',
        'method',
        'amount',
        'paid_on',
        'reference',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * أثر الدفعة على رصيد الحجز (الاسترداد سالب).
     */
    public function signedAmount(): float
    {
        return $this->type === 'refund'
            ? -1 * (float) $this->amount
            : (float) $this->amount;
    }
}
