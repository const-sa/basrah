<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Setting;
use App\Support\BookingPeriod;
use App\Support\ChaletContractTemplate;
use App\Support\Hijri;
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
            $data = $this->buildQuotationData($quotation, $number);

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
     * The values that fill the template's placeholders for a quotation contract.
     *
     * The keys are deliberately the same ones a booking contract writes, so a
     * single template renders both: what has no counterpart here (period, guest
     * count, booked sections) is written as «—» exactly as a missing booking
     * field is, and the screen hides those rows rather than printing a dash.
     *
     * @return array<string, mixed>
     */
    public function buildQuotationData(Quotation $quotation, string $contractNumber): array
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
            // The department is the activity being contracted for (pools, halls…),
            // which is what belongs in the contract's heading — not the item list,
            // which can run to a dozen lines and is printed in full below anyway.
            'subject' => (string) ($quotation->department?->name ?? 'توريد وخدمات'),
            'unit_name' => '—',
            'booking_reference' => '—',
            'sections' => '—',
            // A quotation has no booked date or period. Its validity date is the
            // deadline for accepting it, not a term of the contract, so it is
            // carried under its own key rather than dressed up as a booking date.
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
            'quotation_number' => (string) $quotation->number,
            'quotation_date' => $quotation->created_at?->toDateString() ?? '—',
            'valid_until' => $quotation->valid_until?->toDateString() ?? '—',
            'items' => $lines,
            'subtotal' => number_format((float) $quotation->subtotal, 2),
            'discount_amount' => number_format((float) $quotation->discount_amount, 2),
            'tax_amount' => number_format((float) $quotation->tax_amount, 2),
            'total_amount' => number_format($total, 2),
            'total_amount_words' => Tafqeet::money($total),
            // Nothing is paid at signing: the quotation prices the work, it does
            // not collect for it. The paid and remaining boxes stay on the page
            // so the contract reads the same as a booking contract, and the
            // first receipt voucher is what moves them.
            'deposit_amount' => number_format(0, 2),
            'remaining_amount' => number_format($total, 2),
        ];
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
        $contract->loadMissing('booking.unit');

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

            $contract->update([
                'contract_template_id' => $template->id,
                'body' => $this->render($template->body, $data),
                'terms' => filled($template->terms) ? $this->render($template->terms, $data) : null,
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
            'period' => BookingPeriod::label($booking->period),
            'starts_at' => $booking->starts_at->format('Y-m-d H:i'),
            'ends_at' => $booking->ends_at->format('Y-m-d H:i'),
            'guests_count' => (string) ($booking->guests_count ?? '—'),
            'total_amount' => number_format($total, 2),
            // Words stop a figure being altered after signing — the same
            // reason the receipt voucher carries them.
            'total_amount_words' => Tafqeet::money($total),
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
