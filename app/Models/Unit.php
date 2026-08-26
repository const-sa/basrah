<?php

namespace App\Models;

use App\Support\BookingPeriod;
use App\Support\StayPeriod;
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
        'period_hours',
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
            'period_hours' => 'array',
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

    /**
     * ساعات فترةٍ بعينها كما كُتبت على هذه الوحدة، أو null إن لم تُكتب.
     *
     * الفترة التي لم تُكتب لها ساعات ترجع إلى ساعات الإعدادات، فالوحدة القديمة
     * تعمل كما كانت قبل أن يوجد هذا العمود.
     *
     * @return array{start: string, end: string}|null
     */
    public function periodHours(string $period): ?array
    {
        $hours = $this->period_hours[$period] ?? null;

        if (! is_array($hours)) {
            return null;
        }

        $start = self::time($hours['start'] ?? null);
        $end = self::time($hours['end'] ?? null);

        // نصف ساعات لا يصلح مدًى: من كتب الدخول ولم يكتب الخروج تُقرأ فترته
        // من الإعدادات كاملةً، لا نصفها من هنا ونصفها من هناك.
        return ($start === null || $end === null) ? null : ['start' => $start, 'end' => $end];
    }

    /**
     * ساعة دخول الفترة على هذه الوحدة، أو null لترجع إلى الإعدادات.
     */
    public function periodStart(string $period): ?string
    {
        return $this->periodHours($period)['start'] ?? null;
    }

    /**
     * ساعة خروج الفترة على هذه الوحدة، أو null لترجع إلى الإعدادات.
     */
    public function periodEnd(string $period): ?string
    {
        return $this->periodHours($period)['end'] ?? null;
    }

    /**
     * ساعات كل الفترات سارية المفعول — ما كُتب على الوحدة، وإلا ساعات
     * الإعدادات — جاهزةً لشاشات الحجز حتى تعرض ساعة الوحدة المختارة لا ساعة
     * النظام.
     *
     * @return array<string, array{start: string, end: string}>
     */
    public function effectiveHours(): array
    {
        $hours = [
            StayPeriod::PERIOD => [
                'start' => StayPeriod::checkInTime($this),
                'end' => StayPeriod::checkOutTime($this),
            ],
        ];

        foreach (BookingPeriod::periodsFor($this) as $key => $meta) {
            $hours[$key] = ['start' => $meta['start'], 'end' => $meta['end']];
        }

        return $hours;
    }

    /**
     * الساعة إن كانت HH:MM سليمة، وإلا null.
     *
     * نصف ساعة مكتوبة أو خانة فارغة لا تُبنى منها بداية حجز، فتُهمَل هنا بدل
     * أن تُمرَّر إلى Carbon فتُفسَّر تفسيرًا لا يقصده أحد.
     */
    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
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
     *
     * A chalet carries no such lock. Its rooms are let one at a time, and an
     * exclusive unit closes every other room the moment one is taken — which
     * would leave a divided chalet unsellable to two guests on the same night,
     * the very thing letting it by the room is for. A hall keeps the setting:
     * there, one occasion taking the men's side really can mean the women's
     * side is not on offer to a stranger.
     */
    public function isExclusive(): bool
    {
        if ($this->type === 'chalet') {
            return false;
        }

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
