<?php

namespace App\Models;

use App\Support\BookingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * وحدة قابلة للحجز (قاعة أو شاليه)، وهي أيضًا مركز تكلفة مستقل.
 */
class Unit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'logo_path',
        'manager_id',
        'type',
        'bookable_mode',
        'privacy_mode',
        'capacity',
        'security_deposit',
        'description',
        'notes',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'capacity' => 'integer',
            'sort_order' => 'integer',
            'security_deposit' => 'decimal:2',
        ];
    }

    /**
     * The security deposit normally taken on this unit, or zero when it takes
     * none. What a booking asks for starts here and stays editable — the guest
     * who books three chalets is not always charged three times the usual.
     */
    public function securityDeposit(): float
    {
        return round((float) ($this->security_deposit ?? 0), 2);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(UnitSection::class)->orderBy('sort_order');
    }

    /** مدير الوحدة — يُختار من ملفات الموظفين. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    /**
     * رابط شعار الوحدة، أو null إن لم يُرفع.
     *
     * يُبنى بـ asset لا بـ Storage::url: الثانية تثبّت المضيف على APP_URL،
     * فيخرج الرابط بـ localhost بينما النظام يُقدَّم من منفذ آخر (127.0.0.1:8003)
     * فيتعذّر تحميل الصورة ويظهر السند بلا شعار. وasset يتبع مضيف الطلب نفسه.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path ? asset('storage/'.ltrim($this->logo_path, '/')) : null;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(UnitPrice::class);
    }

    /**
     * Day periods this unit may actually be booked for.
     *
     * Pricing is what turns a period on. A chalet is sold as an overnight
     * stay by default; it only becomes bookable for a morning, an evening or
     * a full day once someone prices that period from the pricing screen. So
     * a period that was never priced is never offered, and no booking can be
     * quoted at 0 because its period had no price behind it.
     *
     * Reads the loaded prices relation — eager-load it when mapping a list.
     *
     * @return list<string>
     */
    public function dayUsePeriods(): array
    {
        return array_values(array_filter(
            BookingPeriod::keys(),
            fn (string $period) => $this->prices
                ->where('period', $period)
                ->where('is_active', true)
                ->contains(fn (UnitPrice $price) => $price->hasAnyPrice()),
        ));
    }

    /**
     * المستخدمون المصرّح لهم بهذه الوحدة تحديدًا.
     *
     * This doubles as the unit's staff list: posting an account here is what
     * the unit form edits, and what scopeVisibleTo() reads back. Permissions
     * are not held here — those come from the account's role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Has this unit any section that can actually be let?
     *
     * A stopped section is not one of them — availability refuses it anyway,
     * so counting it here would offer a room nobody can book. Reads the loaded
     * relation when there is one, since the unit lists map every row.
     */
    public function hasBookableSections(): bool
    {
        return $this->relationLoaded('sections')
            ? $this->sections->contains(fn (UnitSection $s) => (bool) $s->is_active)
            : $this->sections()->where('is_active', true)->exists();
    }

    /**
     * هل يجوز حجز الوحدة كاملة؟
     *
     * A chalet is not sold two ways, so bookable_mode has no say over one: its
     * rooms answer the question instead. What the client calls a «قسم» is a
     * room inside the chalet — a chalet that has rooms is let by the room, and
     * one that has none is let whole. Deriving it rather than storing it is
     * what keeps the two from disagreeing: adding the first room to a chalet
     * must close whole-chalet booking in the same movement, and no separate
     * field has to be remembered for that.
     */
    public function allowsWholeBooking(): bool
    {
        if ($this->type === 'chalet') {
            return ! $this->hasBookableSections();
        }

        return in_array($this->bookable_mode, ['whole', 'both'], true);
    }

    /**
     * هل يجوز حجز قسم منفرد؟
     *
     * Derived for a chalet — see allowsWholeBooking(). A hall is different in
     * kind: it genuinely goes out whole or in sections, and that is a decision
     * about the hall rather than a consequence of having sections at all.
     */
    public function allowsSectionBooking(): bool
    {
        if ($this->type === 'chalet') {
            return $this->hasBookableSections();
        }

        return in_array($this->bookable_mode, ['sections', 'both'], true);
    }

    /**
     * هل حجز قسم واحد يحجب بقية الأقسام عن العملاء الآخرين؟
     */
    public function isExclusive(): bool
    {
        return $this->privacy_mode === 'exclusive';
    }

    /**
     * قصر النتائج على الوحدات التي يملك المستخدم حق الوصول إليها.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->seesAllUnits()) {
            return $query;
        }

        return $query->whereIn('id', $user?->accessibleUnitIds() ?? []);
    }
}
