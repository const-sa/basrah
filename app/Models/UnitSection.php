<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قسم داخل وحدة (رجال / نساء / جناح).
 */
class UnitSection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'unit_id',
        'name',
        'gender',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * A room earns money of its own, so it carries a cost centre of its own
     * from the moment it exists. Waiting for its first booking would leave it
     * off «الإيرادات» until then — and a room that cannot be picked reads as a
     * room that earns nothing.
     */
    protected static function booted(): void
    {
        static::created(fn (self $section) => CostCenter::forSection($section));
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class, 'booking_section')
            ->withPivot('price')
            ->withTimestamps();
    }

    public function prices(): HasMany
    {
        return $this->hasMany(UnitPrice::class, 'unit_section_id');
    }

    /**
     * مرافق القسم — والمشترك منها يؤثر على قاعدة الخصوصية.
     */
    public function facilities(): BelongsToMany
    {
        return $this->belongsToMany(Facility::class, 'facility_unit_section')
            ->withPivot('is_shared')
            ->withTimestamps()
            ->orderBy('sort_order');
    }

    /**
     * هل لهذا القسم مرافق مشتركة مع بقية أقسام الوحدة؟
     */
    public function hasSharedFacilities(): bool
    {
        return $this->facilities()->wherePivot('is_shared', true)->exists();
    }
}
