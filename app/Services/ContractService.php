<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Setting;
use App\Support\BookingPeriod;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * توليد العقود من الحجوزات (§الطبقة أ - بند 2).
 *
 * نص العقد يُجمَّد وقت التوليد، فتعديل القالب لاحقًا لا يمسّ عقدًا صدر.
 */
class ContractService
{
    /**
     * توليد عقد من حجز.
     */
    public function generate(Booking $booking, ?ContractTemplate $template = null, ?int $userId = null): Contract
    {
        $template ??= ContractTemplate::defaultTemplate();

        if (! $template) {
            throw new RuntimeException('لا يوجد قالب عقد فعّال — أضف قالبًا أولًا.');
        }

        return DB::transaction(function () use ($booking, $template, $userId) {
            $booking->loadMissing(['unit', 'client', 'sections']);

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
     * إعادة توليد نص عقد قائم من قالبه الحالي.
     *
     * التجميد يحمي عقدًا وصل العميل، لا مسودةً لم تُرسل بعد: تحرير النموذج
     * ثم إيجاد المسودة على نصه القديم يدفع الموظف لحذفها وتوليد غيرها،
     * فيقفز رقم العقد بلا سبب. والرقم والتاريخ يبقيان كما صدرا.
     */
    public function refresh(Contract $contract, ?ContractTemplate $template = null): Contract
    {
        $template ??= $contract->template ?? ContractTemplate::defaultTemplate();

        if (! $template) {
            throw new RuntimeException('لا يوجد قالب عقد فعّال — أضف قالبًا أولًا.');
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

        return [
            'contract_number' => $contractNumber,
            'contract_date' => now()->toDateString(),
            'org_name' => (string) ($settings->site_name ?? config('app.name')),
            'client_name' => (string) ($booking->client?->name ?? '—'),
            'client_mobile' => (string) ($booking->client?->mobile ?? '—'),
            // رقم الهوية أولًا، فإن لم يُسجَّل يُستعمل الرقم الضريبي للعميل الضريبي.
            'client_id_number' => (string) ($booking->client?->national_id ?: $booking->client?->tax_number ?: '—'),
            'booking_reference' => $booking->reference,
            'unit_name' => (string) ($booking->unit?->name ?? '—'),
            'sections' => $booking->scope === 'whole'
                ? 'الوحدة كاملة'
                : ($booking->sections->pluck('name')->implode('، ') ?: '—'),
            'booking_date' => $booking->booking_date->toDateString(),
            // المناسبة الممتدة أيامًا يجب أن يذكرها العقد صراحةً: التاريخ
            // الواحد فيه يجعل بقية أيامها بلا سند مكتوب. وعقد الشاليه يقرأ
            // نفس المفتاحين بلياليه وتاريخ خروجه، فيصلح القالب للاثنين.
            'days_count' => (string) ($booking->isStay() ? $booking->nightsCount() : $booking->daysCount()),
            'last_day_date' => $booking->isStay() ? $booking->checkOutDate() : $booking->lastDayDate(),
            'period' => BookingPeriod::label($booking->period),
            'starts_at' => $booking->starts_at->format('Y-m-d H:i'),
            'ends_at' => $booking->ends_at->format('Y-m-d H:i'),
            'guests_count' => (string) ($booking->guests_count ?? '—'),
            'total_amount' => number_format((float) $booking->total_amount, 2),
            'deposit_amount' => number_format((float) $booking->deposit_amount, 2),
            'remaining_amount' => number_format($booking->remainingAmount(), 2),
        ];
    }

    /**
     * استبدال {{مفتاح}} بقيمته. المفتاح غير المعروف يُترك كما هو
     * ليظهر للمحرّر أنه خطأ مطبعي بدل أن يختفي بصمت.
     *
     * @param  array<string, string>  $data
     */
    public function render(string $body, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn ($m) => $data[$m[1]] ?? $m[0],
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
