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

    protected $fillable = [
        'booking_id',
        'received_by',
        'type',
        'payment_method_id',
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

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function methodLabel(): string
    {
        return $this->paymentMethod?->name ?? '—';
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
