<?php

namespace App\Models;

use App\Support\NotificationCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قالب رسالة في مكتبة الإشعارات، مصنَّفٌ بقسمٍ ومناسبة.
 */
class NotificationTemplate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category',
        'event',
        'title',
        'body',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * أنسب قالبٍ لمناسبةٍ في قسمٍ معيّن.
     *
     * يُجرَّب القسم الخاص أولاً ثم «عام»: قالب الشاليهات إن وُجد، وإلا
     * القالب العام — حتى لا يصمت النظام لأن قسمًا واحدًا لم يُملأ بعد.
     */
    public static function resolve(string $event, string $category = 'general'): ?self
    {
        $candidates = $category === 'general' ? ['general'] : [$category, 'general'];

        foreach ($candidates as $candidate) {
            $template = static::query()
                ->active()
                ->where('event', $event)
                ->where('category', $candidate)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

            if ($template) {
                return $template;
            }
        }

        return null;
    }

    public function categoryLabel(): string
    {
        return NotificationCatalog::categories()[$this->category]['label'] ?? $this->category;
    }

    public function eventLabel(): string
    {
        return NotificationCatalog::events()[$this->event]['label'] ?? 'رسالة حرّة';
    }
}
