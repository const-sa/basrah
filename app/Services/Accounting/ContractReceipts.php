<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\PaymentMethod;
use App\Models\Treasury;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * سندات القبض المحرَّرة على عقود المسابح — التركيب والصيانة.
 *
 * A hall or chalet contract is drawn from a booking, and the booking has kept
 * its own payment ledger since it was written: the عربون handed over at
 * signing is a row on it, and the contract prints المدفوع والمتبقي from that.
 * A pools contract has no booking behind it, so the same money had nowhere to
 * go — the sheet printed «المدفوع 0.00» however much the client paid.
 *
 * It goes here instead, through the door the accounts already have: a posted
 * سند قبض tied to the contract. One receipt book for the whole business, one
 * numbering series, one till — and the paid and remaining boxes on the sheet
 * are what the vouchers add up to.
 *
 * The credit is unearned revenue, not a sale: a deposit is taken before the
 * pool is dug, and calling it revenue the day it is received would put the
 * income in the wrong month. Revenue is recognized when the work is invoiced,
 * exactly as a booking's is on completion.
 */
class ContractReceipts
{
    /** كسور الهللة لا تمنع قبض المتبقي. */
    private const EPSILON = 0.009;

    public function __construct(private readonly VoucherService $vouchers) {}

    /**
     * قبض مبلغ على عقد وترحيل سنده.
     *
     * @param  array{amount:float|string, payment_method_id?:int|null, treasury_id?:int|null, voucher_date?:string|null, reference?:string|null, description?:string|null}  $data
     */
    public function record(Contract $contract, array $data, ?int $userId = null): Voucher
    {
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw new RuntimeException('المبلغ المقبوض يجب أن يكون أكبر من صفر.');
        }

        if (! $contract->acceptsSettlement()) {
            throw new RuntimeException("العقد {$contract->number} لا يتبقى عليه مبلغ.");
        }

        $remaining = $contract->remainingAmount();

        if ($remaining !== null && $amount > $remaining + self::EPSILON) {
            throw new RuntimeException('المبلغ المقبوض يتجاوز المتبقي على العقد ('.number_format($remaining, 2).').');
        }

        $unearned = Account::where('code', Ledger::UNEARNED_REVENUE)->value('id');

        if (! $unearned) {
            throw new RuntimeException('حساب الإيرادات غير المكتسبة غير موجود في شجرة الحسابات.');
        }

        $method = isset($data['payment_method_id'])
            ? PaymentMethod::find($data['payment_method_id']) ?? PaymentMethod::default()
            : PaymentMethod::default();

        $treasury = $this->treasuryFor($data['treasury_id'] ?? null, $method);

        // العربون هو أول ما يُقبض على العقد، وما بعده دفعة — والسند يسمّي
        // نفسه بذلك فيُقرأ في دفتر القبض بلا رجوع إلى العقد.
        $isDeposit = $contract->paidAmount() <= self::EPSILON;

        return DB::transaction(function () use ($contract, $amount, $method, $treasury, $unearned, $isDeposit, $data, $userId) {
            $voucher = $this->vouchers->create([
                'type' => 'receipt',
                'voucher_date' => $data['voucher_date'] ?? now()->toDateString(),
                'amount' => $amount,
                'treasury_id' => $treasury->id,
                'account_id' => $unearned,
                'cost_center_id' => $this->costCenterFor($contract),
                'client_id' => $contract->client_id,
                'contract_id' => $contract->id,
                'payment_method_id' => $method->id,
                'reference' => $data['reference'] ?? $contract->number,
                'description' => ($data['description'] ?? null)
                    ?: ($isDeposit ? "عربون عقد {$contract->number}" : "دفعة على العقد {$contract->number}"),
            ], $userId);

            return $this->vouchers->post($voucher, $userId);
        });
    }

    /**
     * الخزينة التي يدخلها المبلغ.
     *
     * Chosen by where the payment method deposits — cash into a cash box, a
     * transfer or a card into a bank account — so the employee taking the
     * deposit at the client's house is not asked to pick a treasury off a list
     * before the receipt can be written. An explicit choice still wins.
     */
    public function treasuryFor(?int $treasuryId, PaymentMethod $method): Treasury
    {
        $chosen = $treasuryId ? Treasury::where('is_active', true)->find($treasuryId) : null;

        if ($chosen) {
            return $chosen;
        }

        $active = Treasury::where('is_active', true)->orderBy('id');

        return (clone $active)->where('type', $method->deposits_to)->first()
            ?? $active->first()
            ?? throw new RuntimeException('لا توجد خزينة فعّالة لقبض المبلغ فيها.');
    }

    /**
     * The contract's cost centre — its quotation's department, else the one
     * the form it is printed on belongs to: the pools' or the venues'.
     */
    public function costCenterFor(Contract $contract): int
    {
        $contract->loadMissing('quotation.department');

        if ($department = $contract->quotation?->department) {
            return CostCenter::forDepartment($department)->id;
        }

        $code = match (true) {
            $contract->isPoolsForm() => 'POOLS',
            $contract->isChaletRentalForm() => 'VENUES',
            default => null,
        };

        if ($code && $department = Department::where('code', $code)->first()) {
            return CostCenter::forDepartment($department)->id;
        }

        return CostCenter::general()->id;
    }
}
