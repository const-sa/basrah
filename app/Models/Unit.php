<?php

namespace App\Models;

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
        ];
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
     * المستخدمون المصرّح لهم بهذه الوحدة تحديدًا.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * هل يجوز حجز الوحدة كاملة؟
     */
    public function allowsWholeBooking(): bool
    {
        return in_array($this->bookable_mode, ['whole', 'both'], true);
    }

    /**
     * هل يجوز حجز قسم منفرد؟
     */
    public function allowsSectionBooking(): bool
    {
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
