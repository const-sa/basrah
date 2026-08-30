<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A payment on a booking — deposit, instalment, refund, or a security-deposit
 * movement.
 *
 * Two ledgers run side by side on one booking. The first three types are the
 * price being paid off; the security ones are money held and given back, and
 * never touch the total or what is still owed. Keeping them in one table means
 * one till, one receipt book and one audit trail — SECURITY_TYPES is what
 * separates them wherever the distinction matters.
 */
class BookingPayment extends Model
{
    public const TYPES = [
        'deposit' => 'عربون',
        'payment' => 'دفعة',
        'refund' => 'استرداد',
        'security_deposit' => 'تأمين مقبوض',
        'security_refund' => 'رد تأمين',
        'security_forfeit' => 'خصم من التأمين',
    ];

    /**
     * Security-deposit movements — outside the price of the booking.
     *
     * @var list<string>
     */
    public const SECURITY_TYPES = ['security_deposit', 'security_refund', 'security_forfeit'];

    /**
     * What releases held money: a refund pays it out, a forfeit turns it into
     * revenue. Either way it stops being held.
     *
     * @var list<string>
     */
    public const SECURITY_RELEASES = ['security_refund', 'security_forfeit'];

    protected $fillable = [
        'booking_id',
        'received_by',
        'type',
        'payment_method_id',
        'amount',
        'paid_on',
        'reference',
        'attachment_path',
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
     *
     * A security movement is signed by what it does to the amount being held,
     * so one column reads correctly in both ledgers.
     */
    public function signedAmount(): float
    {
        return $this->type === 'refund' || in_array($this->type, self::SECURITY_RELEASES, true)
            ? -1 * (float) $this->amount
            : (float) $this->amount;
    }

    /**
     * رابط إيصال الدفعة إن أُرفق — صورة الحوالة أو إشعار السداد.
     */
    public function attachmentUrl(): ?string
    {
        return $this->attachment_path ? Storage::url($this->attachment_path) : null;
    }

    /** Is this a security-deposit movement rather than part of the price? */
    public function isSecurity(): bool
    {
        return in_array($this->type, self::SECURITY_TYPES, true);
    }
}
