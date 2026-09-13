<?php

namespace App\Services\Accounting;

use App\Models\BookingPayment;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Sale;
use Illuminate\Support\Collection;

/**
 * كشف حساب العميل الموحَّد.
 *
 * لا حساب ذمم فرعيًا لكل عميل في شجرة الحسابات — حساب المدينين واحد لكل
 * العملاء (Ledger::RECEIVABLES). فالكشف هنا يُبنى من الجداول المصدر مباشرة
 * (حجوزات، مبيعات، عقود تأخذ سندات، دفعات، سندات قبض) لا من القيود، تمامًا
 * كما يُبنى outstanding() الحالي على Client من الحجوزات وحدها — هذا امتداده
 * ليشمل بقية مصادر الدَّين والسداد.
 */
class ClientStatementService
{
    /**
     * إجمالي ما على العميل الآن من كل المصادر.
     */
    public function outstandingFor(Client $client): float
    {
        $bookingDebt = $client->bookings()
            ->where('status', '!=', 'cancelled')
            ->get(['total_amount', 'paid_amount'])
            ->sum(fn ($b) => (float) $b->total_amount - (float) $b->paid_amount);

        $salesDebt = $client->sales()
            ->where('type', 'sale')
            ->get()
            ->sum(fn (Sale $s) => $s->remainingAmount());

        $contractsDebt = $client->contracts()
            ->where('status', '!=', 'cancelled')
            ->get()
            ->filter(fn (Contract $c) => $c->takesReceipts())
            ->sum(fn (Contract $c) => $c->remainingAmount() ?? 0.0);

        return round($bookingDebt + $salesDebt + $contractsDebt, 2);
    }

    /**
     * كشف حساب زمني: كل حركة مدينة (استحقاق) أو دائنة (سداد)، برصيد متراكم.
     *
     * التأمينات (SECURITY_TYPES) مستبعدة من هذا الكشف عمدًا — هي أمانة لا
     * دَين، وتُعرض منفصلة عبر securityFor().
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     opening_balance: float,
     *     total_debit: float,
     *     total_credit: float,
     *     closing_balance: float,
     * }
     */
    public function statementFor(Client $client, ?string $from = null, ?string $to = null): array
    {
        $events = $this->allEvents($client)->sortBy('date')->values();

        $balance = 0.0;
        $withBalance = $events->map(function (array $e) use (&$balance) {
            $balance = round($balance + $e['debit'] - $e['credit'], 2);

            return [...$e, 'balance' => $balance];
        });

        $opening = $from
            ? (float) ($withBalance->filter(fn (array $e) => $e['date'] < $from)->last()['balance'] ?? 0.0)
            : 0.0;

        $rows = $withBalance
            ->when($from, fn (Collection $c) => $c->filter(fn (array $e) => $e['date'] >= $from))
            ->when($to, fn (Collection $c) => $c->filter(fn (array $e) => $e['date'] <= $to))
            ->values();

        return [
            'rows' => $rows->all(),
            'opening_balance' => round($opening, 2),
            'total_debit' => round($rows->sum('debit'), 2),
            'total_credit' => round($rows->sum('credit'), 2),
            'closing_balance' => $rows->isEmpty() ? round($opening, 2) : (float) $rows->last()['balance'],
        ];
    }

    /**
     * حركات التأمين المستلم من العميل عبر حجوزاته — منفصلة عن كشف الدَّين
     * لأنها مال بأمانة لا مقابل خدمة.
     *
     * @return list<array<string, mixed>>
     */
    public function securityFor(Client $client): array
    {
        return $client->payments()
            ->whereIn('type', BookingPayment::SECURITY_TYPES)
            ->with('booking:id,reference')
            ->orderBy('paid_on')
            ->get()
            ->map(fn (BookingPayment $p) => [
                'date' => $p->paid_on->toDateString(),
                'booking_reference' => $p->booking?->reference,
                'type' => $p->type,
                'type_label' => BookingPayment::TYPES[$p->type] ?? $p->type,
                'amount' => (float) $p->amount,
            ])
            ->values()
            ->all();
    }

    /**
     * كل حركات الدَّين والسداد بلا فلترة تاريخ — يُستعمل لحساب الرصيد
     * الافتتاحي بدقة حتى عند فلترة الكشف بفترة.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function allEvents(Client $client): Collection
    {
        $events = collect();

        foreach ($client->bookings()->where('status', '!=', 'cancelled')->get() as $booking) {
            $events->push([
                'date' => $booking->created_at->toDateString(),
                'type' => 'booking',
                'label' => "حجز {$booking->reference}",
                'debit' => (float) $booking->total_amount,
                'credit' => 0.0,
            ]);
        }

        foreach ($client->sales()->where('type', 'sale')->get() as $sale) {
            $events->push([
                'date' => $sale->created_at->toDateString(),
                'type' => 'sale',
                'label' => "فاتورة بيع {$sale->number}",
                'debit' => (float) $sale->total_amount,
                'credit' => 0.0,
            ]);
        }

        foreach ($client->contracts()->where('status', '!=', 'cancelled')->get() as $contract) {
            if (! $contract->takesReceipts() || $contract->totalAmount() === null) {
                continue;
            }

            $events->push([
                'date' => $contract->created_at->toDateString(),
                'type' => 'contract',
                'label' => "عقد {$contract->number}",
                'debit' => $contract->totalAmount(),
                'credit' => 0.0,
            ]);
        }

        foreach ($client->payments()->whereNotIn('type', BookingPayment::SECURITY_TYPES)->get() as $payment) {
            $signed = $payment->signedAmount();

            $events->push([
                'date' => $payment->paid_on->toDateString(),
                'type' => 'booking_payment',
                'label' => BookingPayment::TYPES[$payment->type] ?? $payment->type,
                'debit' => $signed < 0 ? abs($signed) : 0.0,
                'credit' => $signed > 0 ? $signed : 0.0,
            ]);
        }

        foreach ($client->vouchers()->where('status', 'posted')->where('type', 'receipt')->get() as $voucher) {
            $events->push([
                'date' => $voucher->voucher_date->toDateString(),
                'type' => 'voucher',
                'label' => "سند قبض {$voucher->number}",
                'debit' => 0.0,
                'credit' => (float) $voucher->amount,
            ]);
        }

        return $events;
    }
}
