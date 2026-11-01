<?php

use App\Models\Account;
use Illuminate\Database\Migrations\Migration;

/**
 * Two accounts a purchase entry needs, and the books did not have.
 *
 * Input VAT is a claim on the tax authority, not part of the cost of the goods
 * — burying it in inventory would price the stock above what it is worth. It
 * sits under المدينون with the other claims.
 *
 * A discount earned off a supplier is income, but not operating income, so it
 * gets its own group rather than sitting among the halls and the pools. It is
 * credited rather than taken off the stock value because InventoryService
 * values what comes in at its unit cost: netting the discount into inventory
 * here would leave the account permanently below the stock report.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->open('1200', '1220', 'ضريبة القيمة المضافة — مدخلات', 'asset');

        $this->group('4000', '4200', 'إيرادات أخرى', 'revenue');
        $this->open('4200', '4210', 'خصم مكتسب على المشتريات', 'revenue');
    }

    public function down(): void
    {
        // Only while nothing was ever posted on them.
        foreach (['1220', '4210', '4200'] as $code) {
            $account = Account::where('code', $code)->first();

            if ($account && ! $account->lines()->exists()) {
                $account->delete();
            }
        }
    }

    private function group(string $parent, string $code, string $name, string $type): void
    {
        $this->make($parent, $code, $name, $type, true);
    }

    private function open(string $parent, string $code, string $name, string $type): void
    {
        $this->make($parent, $code, $name, $type, false);
    }

    private function make(string $parent, string $code, string $name, string $type, bool $isGroup): void
    {
        Account::firstOrCreate(['code' => $code], [
            'name' => $name,
            'parent_id' => Account::where('code', $parent)->value('id'),
            'type' => $type,
            'is_group' => $isGroup,
            'is_active' => true,
        ]);
    }
};
