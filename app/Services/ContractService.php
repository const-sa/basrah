<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Setting;
use App\Support\ChaletContractTemplate;
use App\Support\Hijri;
use App\Support\PoolInstallationContractTemplate;
use App\Support\PoolMaintenanceContractTemplate;
use App\Support\Tafqeet;
use App\Support\Weekdays;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generating contracts from their source document (§الطبقة أ - بند 2).
 *
 * A contract is never typed from scratch: a hall or chalet contract is
 * generated from its booking, and a pools contract from the quotation the
 * client accepted. Either way the text is frozen at generation time, so
 * editing the template afterwards does not touch a contract already issued.
 */
class ContractService
{
    /** The pool's measurements, taken at the site and typed onto the contract. */
    public const DIMENSIONS = ['pool_width', 'pool_length', 'pool_min_depth', 'pool_max_depth'];

    /**
     * The fields that hold an amount. An edit types «8000» and the sheet
     * prints «8,000.00», so every figure on it reads the same whether it came
     * from a quotation or from the keyboard.
     */
    private const MONEY = [
        'total_amount', 'subtotal', 'discount_amount', 'tax_amount', 'deposit_amount',
        'remaining_amount', 'first_installment', 'second_installment', 'security_deposit',
    ];

    /**
     * توليد عقد من حجز.
     */
    public function generate(Booking $booking, ?ContractTemplate $template = null, ?int $userId = null): Contract
    {
        $booking->loadMissing(['unit', 'client', 'sections']);

        $template ??= $this->templateFor($booking);

        if (! $template) {
            throw new RuntimeException('لا يوجد قالب عقد فعّال — أضف قالبًا أولًا.');
        }

        return DB::transaction(function () use ($booking, $template, $userId) {
            $number = $this->nextNumber();
            $data = $this->buildData($booking, $number);

            return Contract::create([
                'number' => $number,
                'booking_id' => $booking->id,
                'client_id' => $booking->client_id,
                'contract_template_id' => $template->id,
                'created_by' => $userId,
                // النص والشروط يُجمَّدان منفصلين: صفحة العقد تعرض البيانات في
                // صناديقها والشروط في بابها، ودمجهما في عمود واحد كان يفرض
                // على العرض أن يفتّش النص عن موضع بدايتها.
                'body' => $this->render($template->body, $data),
                'terms' => filled($template->terms) ? $this->render($template->terms, $data) : null,
                'data' => $data,
                'status' => 'draft',
            ]);
        });
    }

    /**
     * Generate a contract from an accepted quotation — the pools path.
     *
     * The pools business (sales, installation, maintenance) has no bookings to
     * generate from; its agreement starts as a quotation. Deriving the contract
     * from that quotation instead of retyping it means the contracted price is
     * the quoted price by construction, and the priced lines the client already
     * saw become the contract's scope of work.
     */
    public function generateFromQuotation(Quotation $quotation, ?ContractTemplate $template = null, ?int $userId = null): Contract
    {
        $template ??= ContractTemplate::defaultTemplate();

        if (! $template) {
            throw new RuntimeException('لا يوجد قالب عقد فعّال — أضف قالبًا أولًا.');
        }

        return DB::transaction(function () use ($quotation, $template, $userId) {
            $quotation->loadMissing(['client', 'department', 'items.item']);

            $number = $this->nextNumber();
            $data = $this->buildQuotationData($quotation, $number, $template);

            return Contract::create([
                'number' => $number,
                'booking_id' => null,
                'quotation_id' => $quotation->id,
                'client_id' => $quotation->client_id,
                'contract_template_id' => $template->id,
                'created_by' => $userId,
                'body' => $this->render($template->body, $data),
                'terms' => filled($template->terms) ? $this->render($template->terms, $data) : null,
                'data' => $data,
                'status' => 'draft',
            ]);
        });
    }

    /**
     * Draw a contract straight onto a client, with no source document.
     *
     * Not every job is quoted first: the pools write the installation pad at
     * the client's house, price it there, and hand it over signed. Making the
     * quotation mandatory only pushed the employee to invent one, so the form
     * is drawn on the client and its equipment grid is filled by hand — the
     * value may be left blank for the same reason.
     */
    public function generateDirect(Client $client, ?ContractTemplate $template = null, ?float $total = null, ?int $userId = null): Contract
    {
        $template ??= ContractTemplate::defaultTemplate();

        if (! $template) {
            throw new RuntimeException('لا يوجد قالب عقد فعّال — أضف قالبًا أولًا.');
        }

        return DB::transaction(function () use ($client, $template, $total, $userId) {
            $number = $this->nextNumber();
            $data = $this->buildDirectData($client, $number, $total, $template);

            return Contract::create([
                'number' => $number,
                'booking_id' => null,
                'quotation_id' => null,
                'client_id' => $client->id,
                'contract_template_id' => $template->id,
                'created_by' => $userId,
                'body' => $this->render($template->body, $data),
                'terms' => filled($template->terms) ? $this->render($template->terms, $data) : null,
                'data' => $data,
                'status' => 'draft',
            ]);
        });
    }

    /**
     * The snapshot of a contract drawn on a client alone.
     *
     * The keys are the ones every contract writes; what has no source here —
     * the quotation, its priced lines, the tax it carried — is written as «—»,
     * which the page and the PDF print as an empty fill-in run rather than a
     * dash. A value left blank is meant to be written on the paper.
     *
     * @return array<string, mixed>
     */
    public function buildDirectData(Client $client, string $contractNumber, ?float $total = null, ?ContractTemplate $template = null): array
    {
        $settings = Setting::current();
        $contractDate = now()->toDateString();

        return [
            'contract_number' => $contractNumber,
            'contract_date' => $contractDate,
            'contract_date_hijri' => Hijri::short($contractDate) ?: '—',
            'org_name' => (string) ($settings->site_name ?? config('app.name')),
            'client_name' => (string) $client->name,
            'client_mobile' => (string) ($client->mobile ?: '—'),
            'client_id_number' => (string) ($client->national_id ?: $client->tax_number ?: '—'),
            'client_address' => (string) ($client->tax_address ?: $client->city ?: '—'),
            'subject' => $this->subjectFor($template, null),
            'form' => $this->formFor($template),
            ...$this->blankBookingFields(),
            'quotation_number' => '—',
            'quotation_date' => '—',
            'valid_until' => '—',
            'items' => [],
            'discount_amount' => '—',
            'tax_amount' => '—',
            'is_taxable' => '',
            'tax_rate' => '—',
            // Nothing is collected at signing here either — the first receipt
            // voucher is what moves the paid and remaining boxes.
            ...$this->valueFields($total),
        ];
    }

    /**
     * What a contract with no booking behind it leaves empty — the booking's
     * own fields, and the pool measurements that are taken at the site and
     * typed onto the contract afterwards.
     *
     * @return array<string, string>
     */
    private function blankBookingFields(): array
    {
        return [
            ...array_fill_keys(self::DIMENSIONS, '—'),
            'unit_name' => '—',
            'booking_reference' => '—',
            'sections' => '—',
            'booking_date' => '—',
            'booking_date_hijri' => '—',
            'last_day_date' => '—',
            'last_day_date_hijri' => '—',
            'days_count' => '—',
            'duration_label' => '—',
            'check_in_day' => '—',
            'check_out_day' => '—',
            'check_in_time' => '—',
            'check_out_time' => '—',
            'period' => '—',
            'starts_at' => '—',
            'ends_at' => '—',
            'guests_count' => '—',
            'security_deposit' => '—',
        ];
    }

    /**
     * The values that fill the template's placeholders for a quotation contract.
     *
     * The keys are deliberately the same ones a booking contract writes, so a
     * single template renders both: what has no counterpart here (period, guest
     * count, booked sections) is written as «—» exactly as a missing booking
     * field is, and the screen hides those rows rather than printing a dash.
     *
     * @return array<string, mixed>
     */
    public function buildQuotationData(Quotation $quotation, string $contractNumber, ?ContractTemplate $template = null): array
    {
        $settings = Setting::current();

        $lines = $quotation->items->map(fn (QuotationItem $line) => [
            'name' => $line->item?->name ?? '—',
            'code' => $line->item?->code,
            'quantity' => (float) $line->quantity,
            'unit_price' => number_format((float) $line->unit_price, 2),
            'total_price' => number_format((float) $line->total_price, 2),
        ])->values()->all();

        $total = (float) $quotation->total_amount;

        return [
            'contract_number' => $contractNumber,
            'contract_date' => now()->toDateString(),
            'contract_date_hijri' => Hijri::short(now()->toDateString()) ?: '—',
            'org_name' => (string) ($settings->site_name ?? config('app.name')),
            'client_name' => (string) ($quotation->client?->name ?? '—'),
            'client_mobile' => (string) ($quotation->client?->mobile ?? '—'),
            'client_id_number' => (string) ($quotation->client?->national_id ?: $quotation->client?->tax_number ?: '—'),
            'client_address' => (string) ($quotation->client?->tax_address ?: $quotation->client?->city ?: '—'),
            // A named form titles its own contract; otherwise the department is
            // the activity being contracted for (pools, halls…), which is what
            // belongs in the heading — not the item list, which can run to a
            // dozen lines and is printed in full below anyway.
            'subject' => $this->subjectFor($template, $quotation->department?->name),
            // The layout the contract is printed on, frozen with the rest of
            // the snapshot — see PoolInstallationContractTemplate::FORM.
            'form' => $this->formFor($template),
            // A quotation has no booked date or period. Its validity date is the
            // deadline for accepting it, not a term of the contract, so it is
            // carried under its own key rather than dressed up as a booking date.
            ...$this->blankBookingFields(),
            'quotation_number' => (string) $quotation->number,
            'quotation_date' => $quotation->created_at?->toDateString() ?? '—',
            'valid_until' => $quotation->valid_until?->toDateString() ?? '—',
            'items' => $lines,
            'subtotal' => number_format((float) $quotation->subtotal, 2),
            'discount_amount' => number_format((float) $quotation->discount_amount, 2),
            'tax_amount' => number_format((float) $quotation->tax_amount, 2),
            // النسبة تُقرأ من العرض نفسه فتتجمّد كما كانت يوم التوقيع،
            // شأن المبلغ. وأصنافه قد تختلف نسبها، فتخرج النسبة الوسطى.
            'is_taxable' => (float) $quotation->tax_amount > 0 ? '1' : '',
            'tax_rate' => (float) $quotation->subtotal > 0
                ? rtrim(rtrim(number_format(round((float) $quotation->tax_amount / (float) $quotation->subtotal * 100, 2), 2), '0'), '.')
                : '—',
            'total_amount' => number_format($total, 2),
            'total_amount_words' => Tafqeet::money($total),
            // Nothing is paid at signing: the quotation prices the work, it does
            // not collect for it. The paid and remaining boxes stay on the page
            // so the contract reads the same as a booking contract, and the
            // first receipt voucher is what moves them.
            'deposit_amount' => number_format(0, 2),
            'remaining_amount' => number_format($total, 2),
            ...$this->installments($total),
        ];
    }

    /**
     * The installation form's two equal payments — half at signing, half when
     * the equipment is ordered.
     *
     * The second is the remainder rather than a second half, so an odd value
     * still adds up to the total: two halves of 747.50 written as 373.75 each
     * is right, but rounding both up would contract for one halala more than
     * the client agreed to pay.
     *
     * @return array<string, string>
     */
    private function installments(?float $total): array
    {
        // A contract whose value is left to be written by hand splits nothing.
        if ($total === null) {
            return ['first_installment' => '—', 'second_installment' => '—'];
        }

        $first = round($total / 2, 2);

        return [
            'first_installment' => number_format($first, 2),
            'second_installment' => number_format($total - $first, 2),
        ];
    }

    /**
     * The title the contract prints under.
     *
     * A pinned form names its own contract, so a page drawn on the pools'
     * installation form is headed «عقد التمديد والتركيب» and not by the
     * department that happened to draw it.
     */
    private function subjectFor(?ContractTemplate $template, ?string $fallback): string
    {
        return match ($this->formFor($template)) {
            PoolInstallationContractTemplate::FORM => PoolInstallationContractTemplate::SUBJECT,
            PoolMaintenanceContractTemplate::FORM => PoolMaintenanceContractTemplate::SUBJECT,
            default => (string) ($fallback ?: 'توريد وخدمات'),
        };
    }

    /**
     * The layout a template is printed on, or null for the standard one.
     */
    private function formFor(?ContractTemplate $template): ?string
    {
        return match ($template?->name) {
            PoolInstallationContractTemplate::NAME => PoolInstallationContractTemplate::FORM,
            PoolMaintenanceContractTemplate::NAME => PoolMaintenanceContractTemplate::FORM,
            default => null,
        };
    }

    /**
     * Edit a draft in place, keeping the number it was issued under.
     *
     * Every field the contract prints is writable here — its dates, both
     * parties, the figures, the lines, the notes and the text itself. The
     * snapshot is what the contract says, so this writes to it rather than
     * around it: nothing is stored in a second place that could disagree with
     * the paper. The number is the one exception — it identifies the contract,
     * and a register whose numbers move is no register.
     *
     * @param  array<string, mixed>  $changes
     */
    public function applyEdit(Contract $contract, array $changes): Contract
    {
        $contract->loadMissing('template');

        $data = $contract->data ?? [];

        // The client is applied first, so an edit to a printed name or number
        // in the same submit wins over what the client record carries.
        if (filled($changes['client_id'] ?? null)) {
            $client = Client::findOrFail((int) $changes['client_id']);

            $contract->client_id = $client->id;
            $data['client_name'] = (string) $client->name;
            $data['client_mobile'] = (string) ($client->mobile ?: '—');
            $data['client_id_number'] = (string) ($client->national_id ?: $client->tax_number ?: '—');
            $data['client_address'] = (string) ($client->tax_address ?: $client->city ?: '—');
        }

        $touched = [];

        foreach ((array) ($changes['fields'] ?? []) as $key => $value) {
            if (! array_key_exists($key, ContractTemplate::PLACEHOLDERS) || $key === 'contract_number') {
                continue;
            }

            // «—» is how the snapshot writes an empty field: the page and the
            // PDF print it as a blank run to be filled by hand.
            $next = filled($value) ? $this->fieldValue($key, (string) $value) : '—';

            if (($data[$key] ?? null) !== $next) {
                $touched[] = $key;
            }

            $data[$key] = $next;
        }

        // The words and the two payments follow a corrected value, unless they
        // were rewritten in the same edit — otherwise a fixed figure would
        // leave the sentence under it contradicting the box above.
        if (in_array('total_amount', $touched, true)) {
            $total = $this->amountOf($data['total_amount'] ?? null);

            if (! in_array('total_amount_words', $touched, true)) {
                $data['total_amount_words'] = $total !== null ? Tafqeet::money($total) : '—';
            }

            if (! array_intersect(['first_installment', 'second_installment'], $touched)) {
                $data = [...$data, ...$this->installments($total)];
            }
        }

        if (array_key_exists('items', $changes)) {
            $data['items'] = $this->editedLines((array) $changes['items']);
        }

        $template = $contract->template;

        $contract->fill([
            'body' => (string) $this->rewritten($changes, 'body', $contract->body, $template?->body, $data),
            'terms' => $this->rewritten($changes, 'terms', $contract->terms, $template?->terms, $data),
            'data' => $data,
        ]);

        $contract->save();

        return $contract;
    }

    /**
     * Text the employee rewrote is kept word for word; text left as it was is
     * re-rendered from the template, so a corrected figure reaches the
     * sentences quoting it instead of leaving the old one standing.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $data
     */
    private function rewritten(array $changes, string $key, ?string $current, ?string $source, array $data): ?string
    {
        if (array_key_exists($key, $changes) && trim((string) $changes[$key]) !== trim((string) $current)) {
            return filled($changes[$key]) ? (string) $changes[$key] : null;
        }

        return filled($source) ? $this->render($source, $data) : $current;
    }

    /**
     * A typed field as the contract will print it — an amount is formatted,
     * anything else is kept exactly as written, including an amount spelled
     * out in words rather than digits.
     */
    private function fieldValue(string $key, string $value): string
    {
        $amount = in_array($key, self::MONEY, true) ? $this->amountOf($value) : null;

        return $amount !== null ? number_format($amount, 2) : $value;
    }

    /**
     * A printed figure read back as a number — «8,000.00» is what the snapshot
     * holds, and it is not something to compute with as it stands.
     */
    private function amountOf(?string $value): ?float
    {
        $clean = str_replace(',', '', (string) $value);

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * The money fields as one block — every box that moves when the value does.
     *
     * @return array<string, string>
     */
    private function valueFields(?float $total): array
    {
        $amount = $total !== null ? number_format($total, 2) : '—';

        return [
            'total_amount' => $amount,
            'total_amount_words' => $total !== null ? Tafqeet::money($total) : '—',
            'subtotal' => $amount,
            'remaining_amount' => $amount,
            'deposit_amount' => $total !== null ? number_format(0, 2) : '—',
            ...$this->installments($total),
        ];
    }

    /**
     * The contract's own lines as typed onto it — a description, a count and,
     * where the sheet prints them, prices. Blank rows are dropped rather than
     * printed as empty lines the client is asked to sign against.
     *
     * @param  array<int, mixed>  $lines
     * @return list<array<string, mixed>>
     */
    private function editedLines(array $lines): array
    {
        return collect($lines)
            ->filter(fn ($line) => filled($line['name'] ?? null))
            ->map(fn ($line) => [
                'name' => (string) $line['name'],
                'code' => filled($line['code'] ?? null) ? (string) $line['code'] : null,
                'quantity' => $this->cellValue($line['quantity'] ?? null),
                'unit_price' => $this->lineMoney($line['unit_price'] ?? null),
                'total_price' => $this->lineMoney($line['total_price'] ?? null),
            ])
            ->values()->all();
    }

    /**
     * A line's price as the grid prints it — a figure formatted like every
     * other amount, and words kept as words: a price cell on the paper is
     * sometimes «حسب الاتفاق», and a sheet that swallowed that would print an
     * empty box where something was written.
     */
    private function lineMoney(mixed $value): string
    {
        $amount = $this->amountOf(is_scalar($value) ? (string) $value : null);

        return $amount !== null ? number_format($amount, 2) : trim((string) (is_scalar($value) ? $value : ''));
    }

    /**
     * A counted cell: a number stays a number so it prints and adds up as one,
     * and anything else is kept exactly as typed.
     */
    private function cellValue(mixed $value): string|float
    {
        $amount = $this->amountOf(is_scalar($value) ? (string) $value : null);

        return $amount ?? trim((string) (is_scalar($value) ? $value : ''));
    }

    /**
     * إعادة توليد نص عقد قائم من قالبه الحالي.
     *
     * التجميد يحمي عقدًا وصل العميل، لا مسودةً لم تُرسل بعد: تحرير النموذج
     * ثم إيجاد المسودة على نصه القديم يدفع الموظف لحذفها وتوليد غيرها،
     * فيقفز رقم العقد بلا سبب. والرقم والتاريخ يبقيان كما صدرا.
     */
    public function refresh(Contract $contract, ?ContractTemplate $template = null): Contract
    {
        $contract->loadMissing(['booking.unit', 'quotation.department']);

        // A chalet drawn before the daily-rental form existed is rebuilt on it;
        // anything else keeps the template it was issued on.
        $template ??= $contract->booking?->unit?->type === 'chalet'
            ? $this->templateFor($contract->booking)
            : ($contract->template ?? ContractTemplate::defaultTemplate());

        if (! $template) {
            throw new RuntimeException('لا يوجد قالب عقد فعّال — أضف قالبًا أولًا.');
        }

        // A quotation contract is rebuilt from its own frozen snapshot, not from
        // the quotation: the quotation stays editable after the contract is
        // drawn, and rereading it here would let a later price change rewrite a
        // contract silently. Only the template's wording is refreshed.
        if ($contract->fromQuotation()) {
            $data = $contract->data ?? [];

            // A contract drawn before the installation form existed carries no
            // payments in its snapshot, so they are derived from the total it
            // was frozen at — moving it onto the form must not print the
            // placeholder itself where an amount belongs.
            $data += $this->installments((float) str_replace(',', '', (string) ($data['total_amount'] ?? 0)));

            // The form is part of the template's wording: moving a draft onto
            // the installation form must retitle it and print it on that form's
            // layout. The priced snapshot underneath is left untouched.
            $data['form'] = $this->formFor($template);
            // Moving back off the form retitles it by the activity again, so a
            // draft does not keep a heading its template no longer prints.
            $data['subject'] = $this->subjectFor(
                $template,
                $contract->quotation?->department?->name ?: ($data['subject'] ?? null),
            );

            $contract->update([
                'contract_template_id' => $template->id,
                'body' => $this->render($template->body, $data),
                'terms' => filled($template->terms) ? $this->render($template->terms, $data) : null,
                'data' => $data,
            ]);

            return $contract;
        }

        $booking = $contract->booking;

        if (! $booking) {
            throw new RuntimeException('العقد بلا حجز — لا يمكن إعادة بناء نصه.');
        }

        $booking->loadMissing(['unit', 'client', 'sections']);

        $data = $this->buildData($booking, $contract->number);
        $data['contract_date'] = $contract->created_at?->toDateString() ?? $data['contract_date'];

        $contract->update([
            'contract_template_id' => $template->id,
            'body' => $this->render($template->body, $data),
            'terms' => filled($template->terms) ? $this->render($template->terms, $data) : null,
            'data' => $data,
        ]);

        return $contract;
    }

    /**
     * القيم التي تملأ حقول القالب.
     *
     * @return array<string, string>
     */
    public function buildData(Booking $booking, string $contractNumber): array
    {
        $settings = Setting::current();

        $contractDate = now()->toDateString();
        $checkIn = $booking->booking_date->toDateString();
        $checkOut = $booking->isStay() ? $booking->checkOutDate() : $booking->lastDayDate();
        $total = (float) $booking->total_amount;
        $net = $booking->netAmount();
        $tax = $booking->taxAmount();

        return [
            'contract_number' => $contractNumber,
            'contract_date' => $contractDate,
            'contract_date_hijri' => Hijri::short($contractDate) ?: '—',
            'org_name' => (string) ($settings->site_name ?? config('app.name')),
            'client_name' => (string) ($booking->client?->name ?? '—'),
            'client_mobile' => (string) ($booking->client?->mobile ?? '—'),
            // رقم الهوية أولًا، فإن لم يُسجَّل يُستعمل الرقم الضريبي للعميل الضريبي.
            'client_id_number' => (string) ($booking->client?->national_id ?: $booking->client?->tax_number ?: '—'),
            // The rental form asks for a full address; the city is all a
            // walk-in client usually has on file.
            'client_address' => (string) ($booking->client?->tax_address ?: $booking->client?->city ?: '—'),
            // What a booking contract is about is the unit being rented, so one
            // template can carry {{subject}} and still read correctly for both
            // this and a quotation contract, where the subject is the activity.
            'subject' => (string) ($booking->unit?->name ?? '—'),
            'booking_reference' => $booking->reference,
            'unit_name' => (string) ($booking->unit?->name ?? '—'),
            'sections' => $booking->scope === 'whole'
                ? 'الوحدة كاملة'
                : ($booking->sections->pluck('name')->implode('، ') ?: '—'),
            'booking_date' => $checkIn,
            'booking_date_hijri' => Hijri::short($checkIn) ?: '—',
            // المناسبة الممتدة أيامًا يجب أن يذكرها العقد صراحةً: التاريخ
            // الواحد فيه يجعل بقية أيامها بلا سند مكتوب. وعقد الشاليه يقرأ
            // نفس المفتاحين بلياليه وتاريخ خروجه، فيصلح القالب للاثنين.
            'days_count' => (string) ($booking->isStay() ? $booking->nightsCount() : $booking->daysCount()),
            // Nights for a stay, the period for a day-use chalet — one field
            // the form can print without knowing which it is.
            'duration_label' => $booking->scheduleLabel(),
            'last_day_date' => $checkOut,
            'last_day_date_hijri' => Hijri::short($checkOut) ?: '—',
            // Read off the booking's own range, not the configured hours: an
            // edit to the default check-in hour cannot rewrite a signed paper.
            'check_in_day' => Weekdays::label((int) $booking->starts_at->dayOfWeek),
            'check_out_day' => Weekdays::label((int) $booking->ends_at->dayOfWeek),
            'check_in_time' => $this->timeLabel($booking->starts_at),
            'check_out_time' => $this->timeLabel($booking->ends_at),
            // من الحجز لا من جدول الفترات: الحجز بالساعات لا فترة له في
            // الإعدادات، وقراءته من هناك تطبع مفتاحه الإنجليزي في ورقة عقد.
            'period' => $booking->periodLabel(),
            'starts_at' => $booking->starts_at->format('Y-m-d H:i'),
            'ends_at' => $booking->ends_at->format('Y-m-d H:i'),
            'guests_count' => (string) ($booking->guests_count ?? '—'),
            'total_amount' => number_format($total, 2),
            // Words stop a figure being altered after signing — the same
            // reason the receipt voucher carries them.
            'total_amount_words' => Tafqeet::money($total),
            // الضريبة أُضيفت فوق المُسعَّر فصار الإجمالي شاملًا لها، فيذكرها
            // العقد مفصَّلة تحت قيمته. وتُقرأ من الحجز نفسه فتتجمّد بما حُسب
            // يوم إنشائه: تغيير النسبة بعده لا يمسّ ورقةً وقّعها العميل.
            'is_taxable' => $tax > 0 ? '1' : '',
            'tax_rate' => $tax > 0 && $net > 0
                ? rtrim(rtrim(number_format(round($tax / $net * 100, 2), 2), '0'), '.')
                : '—',
            'tax_amount' => number_format($tax, 2),
            'subtotal' => number_format($net, 2),
            'deposit_amount' => number_format((float) $booking->deposit_amount, 2),
            'remaining_amount' => number_format($booking->remainingAmount(), 2),
            'security_deposit' => number_format((float) ($booking->security_deposit_amount ?? 0), 2),
        ];
    }

    /**
     * The hour as the contract reads it — «02:00 م», not a 24-hour stamp.
     */
    private function timeLabel(DateTimeInterface $moment): string
    {
        $moment = CarbonImmutable::instance($moment);

        return $moment->format('h:i').' '.($moment->hour < 12 ? 'ص' : 'م');
    }

    /**
     * The template a booking is drawn on when the caller names none. A chalet
     * is let on its own daily-rental form; halls keep the default, as before.
     */
    private function templateFor(Booking $booking): ?ContractTemplate
    {
        if ($booking->unit?->type === 'chalet') {
            $chalet = ContractTemplate::where('name', ChaletContractTemplate::NAME)
                ->where('is_active', true)
                ->first();

            if ($chalet) {
                return $chalet;
            }
        }

        return ContractTemplate::defaultTemplate();
    }

    /**
     * استبدال {{مفتاح}} بقيمته. المفتاح غير المعروف يُترك كما هو
     * ليظهر للمحرّر أنه خطأ مطبعي بدل أن يختفي بصمت.
     *
     * A key whose value is not a scalar is left standing too: the snapshot
     * carries the quotation's priced lines under `items`, and those are printed
     * as a table by the contract layout, not spliced into a sentence.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $body, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn ($m) => is_scalar($data[$m[1]] ?? null) ? (string) $data[$m[1]] : $m[0],
            $body,
        ) ?? $body;
    }

    /**
     * معاينة القالب ببيانات حجز دون حفظ عقد.
     */
    public function preview(ContractTemplate $template, Booking $booking): string
    {
        $booking->loadMissing(['unit', 'client', 'sections']);

        return $this->render(
            $template->body."\n\n".($template->terms ?? ''),
            $this->buildData($booking, 'CT-XXXX-0000'),
        );
    }

    private function nextNumber(): string
    {
        $year = now()->year;
        $prefix = "CT-{$year}-";

        $last = Contract::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
