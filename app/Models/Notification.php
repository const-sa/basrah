<?php

namespace App\Models;

use App\Support\NotificationRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One line of one person's inbox.
 *
 * It extends Laravel's own notification so markAsRead, unreadNotifications and
 * Notification::send keep working untouched; the added columns are the ones
 * the screen filters on.
 */
class Notification extends DatabaseNotification
{
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeOfCategory(Builder $query, ?string $category): Builder
    {
        return $category ? $query->where('category', $category) : $query;
    }

    public function scopeOfLevel(Builder $query, ?string $level): Builder
    {
        return $level ? $query->where('level', $level) : $query;
    }

    public function title(): string
    {
        return (string) ($this->data['title'] ?? '');
    }

    public function body(): ?string
    {
        return $this->data['body'] ?? null;
    }

    /** The screen the notice opens — null when the record has no screen. */
    public function link(): ?string
    {
        return $this->data['link'] ?? null;
    }

    public function categoryLabel(): string
    {
        return NotificationRegistry::categoryLabel($this->category);
    }

    public function levelLabel(): string
    {
        return NotificationRegistry::levelLabel($this->level);
    }
}
