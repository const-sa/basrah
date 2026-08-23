<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contract extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'draft' => 'مسوّدة',
        'sent' => 'مُرسل',
        'signed' => 'موقّع',
        'cancelled' => 'ملغي',
    ];

    protected $fillable = [
        'number', 'booking_id', 'quotation_id', 'client_id', 'contract_template_id', 'created_by',
        'body', 'terms', 'data', 'status', 'sent_at', 'signed_at', 'pdf_path',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isSent(): bool
    {
        return in_array($this->status, ['sent', 'signed'], true);
    }

    /**
     * A contract drawn from a quotation rather than a booking — the pools path
     * (sales, installation, maintenance).
     *
     * The source document is the whole difference: this one has priced lines
     * for its scope of work and no unit, date or period, so its page prints a
     * line table where a booking contract prints the rental terms.
     */
    public function fromQuotation(): bool
    {
        return $this->booking_id === null;
    }

    /**
     * What the contract is about, read from the frozen snapshot.
     */
    public function subject(): ?string
    {
        $subject = $this->data['subject'] ?? null;

        return filled($subject) && $subject !== '—' ? (string) $subject : null;
    }

    /**
     * The quotation's priced lines as frozen at generation time.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        $lines = $this->data['items'] ?? [];

        return is_array($lines) ? array_values($lines) : [];
    }
}
