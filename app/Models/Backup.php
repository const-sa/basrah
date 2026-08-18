<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نسخة احتياطية واحدة كما أُخذت (§18).
 */
class Backup extends Model
{
    public const STATUSES = [
        'running' => 'جارية',
        'completed' => 'مكتملة',
        'failed' => 'فاشلة',
    ];

    public const TRIGGERS = [
        'manual' => 'يدوية',
        'schedule' => 'مجدولة',
    ];

    protected $fillable = [
        'filename', 'disk', 'size', 'status', 'error',
        'trigger', 'created_by', 'driver', 'method', 'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function triggerLabel(): string
    {
        return self::TRIGGERS[$this->trigger] ?? $this->trigger;
    }

    /**
     * الحجم بصيغة يقرأها الإنسان — الأرقام الخام لا تُقارَن بالعين.
     */
    public function sizeLabel(): string
    {
        $size = (float) $this->size;

        foreach (['بايت', 'ك.ب', 'م.ب', 'ج.ب'] as $unit) {
            if ($size < 1024 || $unit === 'ج.ب') {
                return round($size, $unit === 'بايت' ? 0 : 1).' '.$unit;
            }

            $size /= 1024;
        }

        return (string) $this->size;
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }
}
