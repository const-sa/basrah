<?php

namespace App\Models;

use App\Support\HallRentalContractTemplate;
use App\Support\PoolInstallationContractTemplate;
use App\Support\PoolMaintenanceContractTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'body', 'terms', 'data', 'paid_amount', 'status', 'sent_at', 'signed_at', 'pdf_path',
    ];

    /** ما دون هذا لا يُعدّ متبقيًا — كسور الهللة لا تُبقي عقدًا مفتوحًا. */
    private const EPSILON = 0.009;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'paid_amount' => 'decimal:2',
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

    /** سندات القبض المحرَّرة على العقد — العربون وما تلاه من دفعات. */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
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
     * Written on a client alone — no booking and no quotation behind it.
     *
     * Only such a contract may have its client, value and equipment lines
     * edited: everywhere else those are the source document's, and correcting
     * them here would put the contract at odds with the booking or the
     * quotation it was drawn from.
     */
    public function isDirect(): bool
    {
        return $this->booking_id === null && $this->quotation_id === null;
    }

    /**
     * Is this contract printed on the pools' piping-and-installation form?
     *
     * Read from the frozen snapshot, not from the template: the form the
     * client signed is settled the day the contract is drawn, and editing or
     * deleting the template afterwards must not relayout it.
     */
    public function isInstallationForm(): bool
    {
        return ($this->data['form'] ?? null) === PoolInstallationContractTemplate::FORM;
    }

    /**
     * Is this contract printed on the pools' monthly-maintenance sheet?
     *
     * Read from the frozen snapshot for the same reason as the pad above.
     */
    public function isMaintenanceForm(): bool
    {
        return ($this->data['form'] ?? null) === PoolMaintenanceContractTemplate::FORM;
    }

    /**
     * Is this contract printed on the halls' numbered rental pad?
     *
     * Read from the frozen snapshot for the same reason as the pads above.
     */
    public function isHallRentalForm(): bool
    {
        return ($this->data['form'] ?? null) === HallRentalContractTemplate::FORM;
    }

    /**
     * Is this one of the pools' own forms — installation or maintenance?
     *
     * These two are contracted for and paid off outside the bookings system,
     * so they are the sheets that carry their own receipt ledger. Everything
     * else either hangs off a booking, which already has one, or is a plain
     * sheet whose amounts are written on the paper.
     */
    public function isPoolsForm(): bool
    {
        return $this->isInstallationForm() || $this->isMaintenanceForm();
    }

    /**
     * The contract's value, read back from the frozen snapshot as a number.
     *
     * Null where the sheet was drawn with no value — the pools price the job
     * at the client's house and write the figure on the paper, and a contract
     * like that has nothing to be paid off against.
     */
    public function totalAmount(): ?float
    {
        $clean = str_replace(',', '', (string) ($this->data['total_amount'] ?? ''));

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    /** ما قُبض على العقد فعلًا — مجموع سنداته المرحَّلة. */
    public function paidAmount(): float
    {
        return round((float) $this->paid_amount, 2);
    }

    /**
     * المتبقي على العقد. عقدٌ بلا قيمة مكتوبة لا متبقي له يُحسب.
     */
    public function remainingAmount(): ?float
    {
        $total = $this->totalAmount();

        return $total === null ? null : round(max(0, $total - $this->paidAmount()), 2);
    }

    /** هل ما زال على العقد ما يُقبض؟ */
    public function acceptsSettlement(): bool
    {
        $remaining = $this->remainingAmount();

        // A contract priced on the paper takes its receipts all the same: the
        // money was collected either way, and refusing it because no figure
        // was typed would push the employee to record it nowhere.
        return $remaining === null || $remaining > self::EPSILON;
    }

    /**
     * A posted receipt raises what the contract has been paid.
     *
     * Capped at the value where there is one, so a double-entered voucher
     * cannot report a contract as more than settled — the extra still stands
     * in the books as its own voucher, which is where an overpayment belongs.
     */
    public function addSettlement(float $amount): void
    {
        $paid = $this->paidAmount() + $amount;
        $total = $this->totalAmount();

        $this->update(['paid_amount' => round($total === null ? $paid : min($total, $paid), 2)]);
    }

    /** إلغاء سند مرحَّل يعيد ما أضافه. */
    public function reverseSettlement(float $amount): void
    {
        $this->update(['paid_amount' => round(max(0, $this->paidAmount() - $amount), 2)]);
    }

    /**
     * «المدفوع» و«المتبقي» كما تُطبعان على الورقة.
     *
     * A pools form reads them off its posted receipts; every other contract
     * keeps the snapshot it was frozen with. The sheet is drawn before a riyal
     * is paid, so a frozen «0.00» would still be printed the day the job is
     * settled in full — while a booking contract froze its booking's real
     * figures, and a plain sheet's are written on the paper.
     *
     * A pools sheet drawn with no value has no remaining to compute, so that
     * box falls back to the snapshot too.
     *
     * @param  array<string, mixed>  $data  the snapshot as the page reads it
     * @return array{deposit_amount: string|null, remaining_amount: string|null}
     */
    public function paidBoxes(array $data): array
    {
        if (! $this->isPoolsForm()) {
            return [
                'deposit_amount' => $data['deposit_amount'] ?? null,
                'remaining_amount' => $data['remaining_amount'] ?? null,
            ];
        }

        $remaining = $this->remainingAmount();

        return [
            'deposit_amount' => number_format($this->paidAmount(), 2),
            'remaining_amount' => $remaining === null
                ? ($data['remaining_amount'] ?? null)
                : number_format($remaining, 2),
        ];
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
