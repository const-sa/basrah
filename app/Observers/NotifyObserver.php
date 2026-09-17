<?php

namespace App\Observers;

use App\Events\RecordChanged;
use App\Support\NotificationRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Turns model writes into one domain event, for the watched models only.
 *
 * Registered centrally in AppServiceProvider from NotificationRegistry, the way
 * the audit observer is registered from AuditLog::SUBJECTS: what is announced
 * stays a list read in one place, not a line hidden in each model.
 */
class NotifyObserver
{
    /**
     * A booking that rolls back never happened, and must not be announced.
     * The first payment is written in the booking's own transaction, so the
     * notice waits for the commit that makes both real.
     */
    public bool $afterCommit = true;

    public function created(Model $record): void
    {
        $this->raise($record, 'created');
    }

    /**
     * A save with no real change is not a change, and the soft-delete column
     * belongs to the deleted/restored hooks below — not to this one.
     */
    public function updated(Model $record): void
    {
        $changes = Arr::except(
            $record->getChanges(),
            ['updated_at', ...NotificationRegistry::ignored($record::class)],
        );

        if ($changes === []) {
            return;
        }

        if ($this->softDeletes($record) && array_key_exists($record->getDeletedAtColumn(), $changes)) {
            return;
        }

        $this->raise($record, 'updated');
    }

    public function deleted(Model $record): void
    {
        $this->raise($record, 'deleted');
    }

    public function restored(Model $record): void
    {
        $this->raise($record, 'restored');
    }

    private function raise(Model $record, string $verb): void
    {
        RecordChanged::dispatch($record, $verb, Auth::user());
    }

    private function softDeletes(Model $record): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($record), true);
    }
}
