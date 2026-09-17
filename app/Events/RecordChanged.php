<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A watched record appeared, changed, or vanished — and who did it.
 *
 * One event for every model rather than thirty named ones: the listeners care
 * about the shape (record + verb + actor), and a new watched model should cost
 * a line in the registry, not a class here and a wiring line elsewhere.
 */
class RecordChanged
{
    use Dispatchable;

    /** @param  'created'|'updated'|'deleted'|'restored'  $verb */
    public function __construct(
        public readonly Model $record,
        public readonly string $verb,
        public readonly ?User $actor = null,
    ) {}
}
