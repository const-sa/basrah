<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use SoftDeletes;

    /** اسم العميل النقدي الافتراضي عند إنشائه أول مرة. */
    public const WALK_IN_NAME = 'عميل نقدي';

    protected $fillable = [
        'name',
        'mobile',
        'email',
        'city',
        'national_id',
        'is_taxable',
        'tax_number',
        'tax_address',
        'is_active',
        'is_walk_in',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
            'is_walk_in' => 'boolean',
        ];
    }

    /**
     * العميل النقدي الافتراضي — واحد لا غير.
     *
     * يُنشأ عند أول طلب إن لم يكن مزروعًا، فلا تتعطّل شاشة البيع على سيدر.
     */
    public static function walkIn(): self
    {
        return static::firstOrCreate(
            ['is_walk_in' => true],
            ['name' => self::WALK_IN_NAME, 'is_active' => true, 'is_taxable' => false],
        );
    }

    public function isWalkIn(): bool
    {
        return (bool) $this->is_walk_in;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    /**
     * دفعات العميل على حجوزاته — لا علاقة مباشرة بينهما في الجداول، فالمرور
     * عبر الحجز هو الطريق: الدفعة تتبع حجزًا، والحجز يتبع عميلًا.
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(BookingPayment::class, Booking::class);
    }

    /**
     * ما على العميل من حجوزاته القائمة — الملغى لا يُطالَب به.
     */
    public function outstanding(): float
    {
        $bookings = $this->bookings()->where('status', '!=', 'cancelled')->get(['total_amount', 'paid_amount']);

        return round((float) $bookings->sum('total_amount') - (float) $bookings->sum('paid_amount'), 2);
    }
}
