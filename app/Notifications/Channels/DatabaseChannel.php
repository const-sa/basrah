<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel as BaseDatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Laravel's database channel, taught to write our own columns too.
 *
 * The framework writes id, type, data and read_at and nothing else; the fields
 * the inbox filters and counts on are columns, not keys inside the JSON, so a
 * notification may hand them over here. Bound in AppServiceProvider.
 */
class DatabaseChannel extends BaseDatabaseChannel
{
    /**
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        return array_merge(
            parent::buildPayload($notifiable, $notification),
            method_exists($notification, 'databaseColumns')
                ? $notification->databaseColumns($notifiable)
                : [],
        );
    }
}
