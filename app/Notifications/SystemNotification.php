<?php

namespace App\Notifications;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

/**
 * One in-app notice, whatever raised it.
 *
 * Not queued on purpose: it is a row per recipient and nothing more, and a
 * notice that waits on a worker is a notice that silently never arrives on a
 * machine where none is running.
 */
class SystemNotification extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly string $category = 'system',
        public readonly string $event = 'system.notice',
        public readonly string $level = 'info',
        public readonly ?string $link = null,
        public readonly ?int $actorId = null,
        public readonly ?string $actorName = null,
        public readonly ?Model $subject = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * What is only ever rendered.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
        ];
    }

    /**
     * What is filtered, counted and indexed — read by our database channel.
     *
     * @return array<string, mixed>
     */
    public function databaseColumns(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'event' => $this->event,
            'level' => $this->level,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'subject_type' => $this->subject?->getMorphClass(),
            'subject_id' => $this->subject?->getKey(),
        ];
    }
}
