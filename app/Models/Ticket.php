<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'number',
        'client_id',
        'title',
        'content',
        'attachment_path',
        'status',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // توليد رقم تذكرة فريد بعد الإنشاء: TKT-000001
        static::created(function (Ticket $ticket) {
            if (blank($ticket->number)) {
                $ticket->forceFill([
                    'number' => 'TKT-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT),
                ])->saveQuietly();
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
