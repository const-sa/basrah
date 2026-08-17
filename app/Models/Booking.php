<?php

namespace App\Models;

use App\Observers\BookingObserver;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(BookingObserver::class)]
class Booking extends Model
{
    use SoftDeletes;

    /**
     * بادئة رقم الحجز — الرقم بعدها متسلسل يبدأ من 1 (a-1، a-2…).
     * موضعها هنا لا في مولّد الرقم لأن الترحيل والاختبارات يقرآنها أيضًا.
     */
    public const REFERENCE_PREFIX = 'a-';

    /**
     * حالات الحجز السبع كما نصّ عليها العرض المعتمد، بترتيب دورة الحياة.
     *
     * «متاح» الواردة في العرض ليست حالة حجز بل غياب الحجز: التقويم يعرضها
     * للوحدة أو اليوم الخالي، فلا موضع لها في هذا الجدول.
     */
    public const STATUSES = [
        'tentative' => 'حجز مبدئي',
        'pending_deposit' => 'بانتظار العربون',
        'confirmed' => 'حجز مؤكد',
        'checked_in' => 'تم الدخول',
        'checked_out' => 'تم الخروج',
        'postponed' => 'مؤجل',
        'cancelled' => 'ملغي',
    ];

    /**
     * الحالات التي تشغل الوحدة فعليًا — وحدها تُحتسب في كشف التعارض.
     *
     * «بانتظار العربون» يحجز التاريخ كالمبدئي، وإلا بيع اليوم مرتين قبل
     * وصول العربون. والمؤجل خارجها: التأجيل يحرّر الفترة ليُعاد بيعها،
     * والملغي كذلك.
     */
    public const BLOCKING_STATUSES = [
        'tentative',
        'pending_deposit',
        'confirmed',
        'checked_in',
        'checked_out',
    ];

    /**
     * الحالات النهائية — لا تُغيَّر بعدها حالة ولا يُرسَل تذكير.
     */
    public const CLOSED_STATUSES = ['checked_out', 'postponed', 'cancelled'];

    /**
     * مصادر الحجز.
     */
    public const SOURCES = [
        'admin' => 'من الإدارة',
        'online' => 'حجز أونلاين',
    ];

    /**
     * ألوان حالات التقويم.
     */
    public const STATUS_COLORS = [
        'tentative' => 'amber',
        'pending_deposit' => 'orange',
        'confirmed' => 'emerald',
        'checked_in' => 'sky',
        'checked_out' => 'slate',
        'postponed' => 'violet',
        'cancelled' => 'red',
    ];

    protected $fillable = [
        'reference',
        'unit_id',
        'client_id',
        'event_type_id',
        'package_id',
        'created_by',
        'source',
        'scope',
        'period',
        'booking_date',
        'check_out_date',
        'nights',
        'days_count',
        'starts_at',
        'ends_at',
        'status',
        'base_amount',
        'package_amount',
        'event_fee_amount',
        'addons_amount',
        'discount_amount',
        'total_amount',
        'deposit_amount',
        'paid_amount',
        'guests_count',
        'notes',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'booking_date' => 'date',
            'check_out_date' => 'date',
            'nights' => 'integer',
            'days_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'base_amount' => 'decimal:2',
            'package_amount' => 'decimal:2',
            'event_fee_amount' => 'decimal:2',
            'addons_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    // ── العلاقات ──────────────────────────────────────────────

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(UnitSection::class, 'booking_section')
            ->withPivot('price')
            ->withTimestamps();
    }

    public function addons(): BelongsToMany
    {
        return $this->belongsToMany(Addon::class, 'booking_addon')
            ->withPivot(['quantity', 'unit_price', 'total'])
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BookingPayment::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    // ── حسابات مالية ─────────────────────────────────────────

    /**
     * المتبقي على العميل بعد ما سدّده.
     */
    public function remainingAmount(): float
    {
        return round((float) $this->total_amount - (float) $this->paid_amount, 2);
    }

    public function isFullyPaid(): bool
    {
        return $this->remainingAmount() <= 0;
    }

    /**
     * هل استُوفي العربون المطلوب؟
     */
    public function isDepositSettled(): bool
    {
        return (float) $this->paid_amount >= (float) $this->deposit_amount;
    }

    /**
     * إعادة احتساب المسدَّد من الدفعات الفعلية (الاسترداد يُخصم).
     */
    public function recalculatePaidAmount(): void
    {
        $paid = $this->payments()
            ->selectRaw("SUM(CASE WHEN type = 'refund' THEN -amount ELSE amount END) AS total")
            ->value('total');

        $this->update(['paid_amount' => round((float) $paid, 2)]);
    }

    // ── الحالة ───────────────────────────────────────────────

    /**
     * هل يشغل هذا الحجز الوحدة فعليًا؟
     */
    public function isBlocking(): bool
    {
        return in_array($this->status, self::BLOCKING_STATUSES, true);
    }

    /**
     * هل انتهى مسار الحجز؟ (خرج أو أُجِّل أو أُلغي)
     */
    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function periodLabel(): string
    {
        return BookingPeriod::label($this->period);
    }

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source] ?? 'من الإدارة';
    }

    /**
     * هل جاء هذا الحجز من الموقع العام؟
     */
    public function isOnline(): bool
    {
        return $this->source === 'online';
    }

    public function coversWholeUnit(): bool
    {
        return $this->scope === 'whole';
    }

    // ── نوع الحجز ────────────────────────────────────────────

    /**
     * حجز إقامة (شاليه) أم حجز مناسبة (قاعة)؟
     *
     * الفصل يقوم على الفترة لا على نوع الوحدة: الفترة هي ما يحدد شكل الحجز
     * فعليًا، وقراءتها لا تستدعي تحميل الوحدة مع كل صف في القائمة.
     */
    public function isStay(): bool
    {
        return $this->period === StayPeriod::PERIOD;
    }

    /**
     * عدد ليالي الإقامة — ليلة واحدة على الأقل، وصفر لحجز القاعة.
     */
    public function nightsCount(): int
    {
        if (! $this->isStay()) {
            return 0;
        }

        return $this->nights
            ?? StayPeriod::nights($this->booking_date->toDateString(), $this->checkOutDate());
    }

    /**
     * تاريخ الخروج — يُشتق من المدى إن لم يُخزَّن (حجوزات ما قبل الفصل).
     */
    public function checkOutDate(): string
    {
        return ($this->check_out_date ?? $this->ends_at)->toDateString();
    }

    /**
     * عدد أيام المناسبة — يوم واحد في القاعة ما لم يُسجَّل غيره، وصفر للإقامة.
     */
    public function daysCount(): int
    {
        return $this->isStay() ? 0 : BookingPeriod::days($this->days_count);
    }

    /**
     * تاريخ آخر يوم في المناسبة — نفس تاريخ البداية في اليوم الواحد.
     */
    public function lastDayDate(): string
    {
        return BookingPeriod::lastDay($this->booking_date->toDateString(), $this->daysCount());
    }

    /**
     * أيام المناسبة كتواريخ — يستعملها التقويم ليعرض الحجز في أيامه كلها.
     *
     * @return list<string>
     */
    public function dayDates(): array
    {
        return array_map(
            fn ($day) => $day->toDateString(),
            BookingPeriod::dayDates($this->booking_date->toDateString(), $this->daysCount()),
        );
    }

    /**
     * وصف الإقامة أو الفترة كما يُعرض في القائمة والعقد.
     */
    public function scheduleLabel(): string
    {
        if (! $this->isStay()) {
            $days = $this->daysCount();

            // اليوم الواحد هو الغالب فلا يُوسَم بعدده: «مسائي» أوضح من
            // «مسائي — يوم واحد». وما تجاوزه يُذكر عدده لأنه استثناء مؤثر.
            return $days > 1
                ? $this->periodLabel()." — {$this->daysLabel($days)}"
                : $this->periodLabel();
        }

        $nights = $this->nightsCount();

        return match (true) {
            $nights === 1 => 'ليلة واحدة',
            $nights === 2 => 'ليلتان',
            $nights <= 10 => "{$nights} ليالٍ",
            default => "{$nights} ليلة",
        };
    }

    /**
     * عدد الأيام بصيغته العربية الصحيحة.
     */
    public function daysLabel(?int $days = null): string
    {
        $days ??= $this->daysCount();

        return match (true) {
            $days === 1 => 'يوم واحد',
            $days === 2 => 'يومان',
            $days <= 10 => "{$days} أيام",
            default => "{$days} يومًا",
        };
    }

    // ── النطاقات ─────────────────────────────────────────────

    /**
     * حجوزات القاعات — كل ما ليس إقامة ممتدة.
     */
    public function scopeEvents(Builder $query): Builder
    {
        return $query->where('period', '!=', StayPeriod::PERIOD);
    }

    /**
     * حجوزات الشاليهات — الإقامات الممتدة بالليالي.
     */
    public function scopeStays(Builder $query): Builder
    {
        return $query->where('period', StayPeriod::PERIOD);
    }

    // ── النطاقات ─────────────────────────────────────────────

    /**
     * الحجوزات التي تشغل الوحدة (تُستثنى الملغاة و«لم يحضر»).
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', self::BLOCKING_STATUSES);
    }

    /**
     * الحجوزات المتقاطعة زمنيًا مع المدى المعطى.
     * التقاطع الصارم: يبدأ أحدهما قبل انتهاء الآخر والعكس — التلامس في الحد ليس تعارضًا.
     */
    public function scopeOverlapping(Builder $query, string $startsAt, string $endsAt): Builder
    {
        return $query->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }

    /**
     * قصر الحجوزات على الوحدات التي يصل إليها المستخدم.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->seesAllUnits()) {
            return $query;
        }

        return $query->whereIn('unit_id', $user?->accessibleUnitIds() ?? []);
    }
}
