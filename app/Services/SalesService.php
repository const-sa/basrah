<?php

namespace App\Services;

use App\Models\Client;
use App\Models\CostCenter;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Services\Accounting\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * إتمام عمليات البيع والمرتجع من شاشة الكاشير.
 *
 * البيع الواحد يمسّ ثلاثة أنظمة معًا: المخزون والصندوق والدفاتر.
 * لذلك يجري كله في معاملة واحدة — لا فاتورة بلا خصم مخزون ولا قيد.
 */
class SalesService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly Ledger $ledger,
    ) {}

    /**
     * إتمام فاتورة بيع.
     *
     * @param  array{
     *     lines: list<array{item_id:int, quantity:float, unit_price?:float, discount_amount?:float}>,
     *     client_id?:int|null, unit_id?:int|null, booking_id?:int|null,
     *     payment_method_id?:int|null, discount_amount?:float, paid_amount?:float|null, notes?:string|null
     * }  $data
     *
     * @throws ValidationException عند نفاد رصيد أي صنف
     */
    public function checkout(array $data, ?int $userId = null): Sale
    {
        if (empty($data['lines'])) {
            throw new RuntimeException('لا يمكن إتمام فاتورة بلا أصناف.');
        }

        $method = isset($data['payment_method_id'])
            ? PaymentMethod::findOrFail($data['payment_method_id'])
            : PaymentMethod::default();

        return DB::transaction(function () use ($data, $userId, $method) {
            $sale = Sale::create([
                'number' => $this->nextNumber('sale'),
                'user_id' => $userId,
                // بلا عميل محدد تُحمَل الفاتورة على العميل النقدي، فلا تبقى بلا طرف.
                'client_id' => $data['client_id'] ?? Client::walkIn()->id,
                'unit_id' => $data['unit_id'] ?? null,
                'department_id' => $data['department_id'] ?? null,
                'booking_id' => $data['booking_id'] ?? null,
                'type' => 'sale',
                'payment_method_id' => $method->id,
                'notes' => $data['notes'] ?? null,
            ]);

            [$subtotal, $tax, $cost] = $this->buildLines($sale, $data['lines'], $userId);

            $discount = round((float) ($data['discount_amount'] ?? 0), 2);
            $total = round(max(0, $subtotal + $tax - $discount), 2);

            $sale->update([
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'cost_amount' => $cost,
                'paid_amount' => $this->resolvePaidAmount($data, $total, $method),
            ]);

            $this->postSaleEntry($sale->fresh());

            return $sale->fresh(['lines.item', 'client']);
        });
    }

    /**
     * المبلغ المقبوض فعلًا عند إصدار الفاتورة.
     *
     * بلا تحديد: الآجل يبدأ بلا سداد وغيره مسدَّد كاملًا — وهو السلوك المعتاد.
     * ومع تحديد الكاشير مبلغًا، يُقبل كما هو محدودًا بين صفر وإجمالي الفاتورة
     * حتى لا يُسجَّل مقبوض أكبر من المستحق.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolvePaidAmount(array $data, float $total, PaymentMethod $method): float
    {
        if (! isset($data['paid_amount'])) {
            return $method->is_credit ? 0.0 : $total;
        }

        return round(min($total, max(0, (float) $data['paid_amount'])), 2);
    }

    /**
     * مرتجع كلي أو جزئي من فاتورة.
     *
     * @param  array<int, float>  $quantities  معرّف الصنف => الكمية المرتجعة
     */
    public function refund(Sale $original, array $quantities, ?int $userId = null, ?string $reason = null): Sale
    {
        if ($original->isReturn()) {
            throw new RuntimeException('لا يُرتجع من فاتورة مرتجع.');
        }

        return DB::transaction(function () use ($original, $quantities, $userId, $reason) {
            $original->loadMissing('lines.item');

            $return = Sale::create([
                'number' => $this->nextNumber('return'),
                'user_id' => $userId,
                'client_id' => $original->client_id,
                'unit_id' => $original->unit_id,
                'department_id' => $original->department_id,
                'booking_id' => $original->booking_id,
                'type' => 'return',
                'original_sale_id' => $original->id,
                'payment_method_id' => $original->payment_method_id,
                'notes' => $reason,
            ]);

            $subtotal = 0.0;
            $tax = 0.0;
            $cost = 0.0;

            foreach ($original->lines as $line) {
                $qty = round((float) ($quantities[$line->item_id] ?? 0), 3);

                if ($qty <= 0) {
                    continue;
                }

                // المرتجع لا يتجاوز المباع منقوصًا منه ما سبق إرجاعه.
                $available = (float) $line->quantity - $original->returnedQuantityFor($line->item_id);

                if ($qty > $available + 0.001) {
                    throw ValidationException::withMessages([
                        'quantities' => "الكمية المرتجعة من «{$line->item->name}» تتجاوز المتاح ({$available}).",
                    ]);
                }

                $lineTotal = round((float) $line->unit_price * $qty, 2);
                $lineTax = round($lineTotal * (float) $line->item->tax_rate / 100, 2);
                $lineCost = round((float) $line->unit_cost * $qty, 2);

                $return->lines()->create([
                    'item_id' => $line->item_id,
                    'quantity' => $qty,
                    'unit_price' => $line->unit_price,
                    'unit_cost' => $line->unit_cost,
                    'tax_amount' => $lineTax,
                    'total' => $lineTotal,
                ]);

                $this->inventory->restoreForReturn($line->item, $qty, $return, $userId);

                $subtotal += $lineTotal;
                $tax += $lineTax;
                $cost += $lineCost;
            }

            if ($subtotal <= 0) {
                throw new RuntimeException('لم تُحدَّد كمية صالحة للإرجاع.');
            }

            $total = round($subtotal + $tax, 2);

            $return->update([
                'subtotal' => round($subtotal, 2),
                'tax_amount' => round($tax, 2),
                'total_amount' => $total,
                'cost_amount' => round($cost, 2),
                'paid_amount' => $total,
            ]);

            $this->postReturnEntry($return->fresh());

            return $return->fresh(['lines.item']);
        });
    }

    /**
     * بناء سطور الفاتورة مع فحص الرصيد وخصمه.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: float, 1: float, 2: float} [الإجمالي قبل الضريبة, الضريبة, التكلفة]
     */
    private function buildLines(Sale $sale, array $lines, ?int $userId): array
    {
        $subtotal = 0.0;
        $tax = 0.0;
        $cost = 0.0;

        foreach ($lines as $row) {
            $item = Item::with('components')->findOrFail($row['item_id']);
            $qty = round((float) $row['quantity'], 3);

            if ($qty <= 0) {
                continue;
            }

            // الكمية الكسرية للأصناف بالقياس فقط.
            if (! $item->allowsFractionalQuantity() && fmod($qty, 1.0) !== 0.0) {
                throw ValidationException::withMessages([
                    'lines' => "الصنف «{$item->name}» لا يقبل كمية كسرية.",
                ]);
            }

            $availability = $this->inventory->checkAvailability($item, $qty);

            if (! $availability['ok']) {
                throw ValidationException::withMessages(['lines' => $availability['reason']]);
            }

            $price = round((float) ($row['unit_price'] ?? $item->price), 2);
            $lineDiscount = round((float) ($row['discount_amount'] ?? 0), 2);
            $lineTotal = round(max(0, $price * $qty - $lineDiscount), 2);
            $lineTax = round($lineTotal * (float) $item->tax_rate / 100, 2);
            $lineCost = round((float) $item->cost * $qty, 2);

            $sale->lines()->create([
                'item_id' => $item->id,
                'quantity' => $qty,
                'unit_price' => $price,
                'unit_cost' => $item->cost,
                'discount_amount' => $lineDiscount,
                'tax_amount' => $lineTax,
                'total' => $lineTotal,
            ]);

            $this->inventory->consumeForSale($item, $qty, $sale, $userId);

            $subtotal += $lineTotal;
            $tax += $lineTax;
            $cost += $lineCost;
        }

        return [round($subtotal, 2), round($tax, 2), round($cost, 2)];
    }

    /**
     * قيد البيع: مدين النقدية/البنك بالمقبوض والذمم بالمتبقي، دائن الإيراد
     * بالإجمالي — وقيد تكلفة المبيعات.
     *
     * الفصل ضروري مع السداد الجزئي: ما دخل الخزينة يُثبت فيها، وما بقي دَين
     * على العميل لا يجوز أن يظهر نقدًا.
     */
    private function postSaleEntry(Sale $sale): void
    {
        $costCenter = $this->costCenterFor($sale);

        $total = (float) $sale->total_amount;
        $paid = round(min($total, max(0, (float) $sale->paid_amount)), 2);
        $due = round($total - $paid, 2);

        // المقبوض يدخل بحسب أداة الدفع؛ والآجل المدفوع جزئيًا نقدُه في الصندوق
        // (لذلك «على الحساب» تحمل deposits_to = cash).
        $collectedAccount = $sale->paymentMethod()->firstOrFail()->ledgerAccount();

        $lines = [];

        if ($due > 0) {
            if ($paid > 0) {
                $lines[] = ['account' => $collectedAccount, 'debit' => $paid, 'cost_center_id' => $costCenter];
            }

            $lines[] = ['account' => Ledger::RECEIVABLES, 'debit' => $due, 'cost_center_id' => $costCenter];
        } else {
            $lines[] = ['account' => $collectedAccount, 'debit' => $total, 'cost_center_id' => $costCenter];
        }

        $lines[] = ['account' => Ledger::SALES_REVENUE, 'credit' => $total, 'cost_center_id' => $costCenter];

        // تكلفة المبيعات تُثبت فقط إن كان للمباع تكلفة (الخدمي بلا تكلفة).
        if ((float) $sale->cost_amount > 0) {
            $lines[] = ['account' => Ledger::COGS, 'debit' => (float) $sale->cost_amount, 'cost_center_id' => $costCenter];
            $lines[] = ['account' => Ledger::INVENTORY, 'credit' => (float) $sale->cost_amount, 'cost_center_id' => $costCenter];
        }

        $this->ledger->post(
            now()->toDateString(),
            "فاتورة مبيعات {$sale->number}",
            $lines,
            'sale',
            $sale,
            $sale->user_id,
        );
    }

    /**
     * قيد المرتجع — عكس اتجاه قيد البيع.
     */
    private function postReturnEntry(Sale $return): void
    {
        $costCenter = $this->costCenterFor($return);

        // الآجل يعود على ذمة العميل لا على الخزينة — المال لم يُقبض منه أصلًا.
        $creditAccount = $return->paymentMethod()->firstOrFail()->refundAccount();

        $lines = [
            ['account' => Ledger::SALES_REVENUE, 'debit' => (float) $return->total_amount, 'cost_center_id' => $costCenter],
            ['account' => $creditAccount, 'credit' => (float) $return->total_amount, 'cost_center_id' => $costCenter],
        ];

        if ((float) $return->cost_amount > 0) {
            $lines[] = ['account' => Ledger::INVENTORY, 'debit' => (float) $return->cost_amount, 'cost_center_id' => $costCenter];
            $lines[] = ['account' => Ledger::COGS, 'credit' => (float) $return->cost_amount, 'cost_center_id' => $costCenter];
        }

        $this->ledger->post(
            now()->toDateString(),
            "مرتجع مبيعات {$return->number}",
            $lines,
            'sale',
            $return,
            $return->user_id,
        );
    }

    /**
     * مركز تكلفة الفاتورة.
     *
     * الأصل أن الفاتورة تتبع قسمها (المسابح مثلًا) فتهبط على مركزه،
     * ويبقى ربطها بوحدة ممكنًا (بيع لحساب حجز) فيتقدّم حينها.
     */
    public function costCenterFor(Sale $sale): int
    {
        if ($sale->unit_id && $sale->unit) {
            return CostCenter::forUnit($sale->unit)->id;
        }

        if ($sale->department_id && $sale->department) {
            return CostCenter::forDepartment($sale->department)->id;
        }

        return CostCenter::general()->id;
    }

    private function nextNumber(string $type): string
    {
        $prefix = $type === 'return' ? 'RT-' : 'SL-';
        $prefix .= now()->year.'-';

        $last = Sale::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
