<?php

namespace App\Models;

use App\Support\ActivitySegment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * رقم واتساب لقسمٍ أو لوحدة، باشتراكه ومعرّفات بوابته.
 *
 * رمز الوصول مشفّر بمفتاح التطبيق. ونسخةٌ مستعادة بمفتاحٍ آخر تقرؤه فارغاً
 * لا خطأً، فتظهر الحسابات غير مربوطة بدل أن تنهار الشاشة.
 */
class WhatsappAccount extends Model
{
    protected $fillable = [
        'name', 'section', 'unit_id', 'driver', 'base_url', 'instance_id',
        'access_token', 'wa_number', 'connected_at', 'is_active', 'sort_order',
    ];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected function accessToken(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => blank($value)
                ? ''
                : (string) rescue(fn () => Crypt::decryptString($value), '', false),
            set: fn (?string $value) => blank($value) ? null : Crypt::encryptString(trim($value)),
        );
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /** وحدةٌ بعينها أو القسم كله — ما يُكتب تحت اسم الحساب. */
    public function targetLabel(): string
    {
        $section = ActivitySegment::label((string) $this->section);

        return $this->unit ? $section.' — '.$this->unit->name : $section.' (القسم كله)';
    }

    public function hasCredentials(): bool
    {
        return trim((string) $this->instance_id) !== '' && $this->access_token !== '';
    }
}
