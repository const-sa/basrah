<?php

namespace App\Listeners;

use App\Events\RecordChanged;
use App\Notifications\SystemNotification;
use App\Support\NotificationAudience;
use App\Support\NotificationRegistry;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Announces a watched record's change to whoever is entitled to hear it.
 *
 * Auto-discovered by the framework from the type hint below, so a second
 * listener on the same event costs a file and no wiring.
 *
 * Failing to announce never undoes the deed: a booking that could not be
 * broadcast is still a booking. The error is logged and the work goes on.
 */
class SendRecordNotification
{
    public function handle(RecordChanged $event): void
    {
        $rule = NotificationRegistry::for($event->record::class);
        $level = $rule['events'][$event->verb] ?? null;

        if ($rule === null || $level === null) {
            return;
        }

        try {
            $recipients = NotificationAudience::for($rule, $event->record);

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new SystemNotification(
                title: NotificationRegistry::title($rule, $event->verb),
                body: NotificationRegistry::describe($event->record),
                category: $rule['category'],
                event: NotificationRegistry::event($event->record, $event->verb),
                level: $level,
                link: $this->link($event),
                actorId: $event->actor?->getKey(),
                actorName: $event->actor?->name ?? 'النظام',
                subject: $event->record,
            ));
        } catch (Throwable $e) {
            Log::error('تعذّر إرسال إشعار النظام', [
                'model' => $event->record::class,
                'id' => $event->record->getKey(),
                'verb' => $event->verb,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A deleted record's own screen is gone; the archive is where it now lives,
     * and a record deleted for good leaves nothing to open.
     */
    private function link(RecordChanged $event): ?string
    {
        if ($event->verb !== 'deleted') {
            return NotificationRegistry::link($event->record);
        }

        return in_array(SoftDeletes::class, class_uses_recursive($event->record), true)
            ? '/admin/archive'
            : null;
    }
}
