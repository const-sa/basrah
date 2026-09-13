<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\Accounting\DepreciationService;
use App\Services\Accounting\Ledger;
use Database\Seeders\AccountsSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * تسجيل الأصول الثابتة وترحيل إهلاكها الشهري بالقسط الثابت.
 */
class DepreciationServiceTest extends TestCase
{
    use RefreshDatabase;

    private DepreciationService $depreciation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, AccountsSeeder::class]);
        $this->depreciation = app(DepreciationService::class);
    }

    public function test_monthly_depreciation_is_cost_minus_salvage_over_useful_life(): void
    {
        $asset = $this->depreciation->registerAsset([
            'name' => 'مكيف مركزي',
            'purchase_date' => '2026-01-01',
            'cost' => 12000,
            'salvage_value' => 0,
            'useful_life_months' => 24,
        ]);

        $this->assertSame(500.0, $asset->monthlyDepreciation());
        $this->assertStringStartsWith('FA-', $asset->code);
    }

    public function test_posting_a_month_creates_one_consolidated_entry_and_updates_accumulated_depreciation(): void
    {
        $assetA = $this->depreciation->registerAsset([
            'name' => 'أصل أ', 'purchase_date' => '2026-01-01', 'cost' => 1200, 'salvage_value' => 0, 'useful_life_months' => 12,
        ]);
        $assetB = $this->depreciation->registerAsset([
            'name' => 'أصل ب', 'purchase_date' => '2026-01-01', 'cost' => 2400, 'salvage_value' => 0, 'useful_life_months' => 24,
        ]);

        $result = $this->depreciation->postMonthlyDepreciation('2026-09');

        $this->assertSame(2, $result['assets_count']);
        $this->assertSame(200.0, $result['total_amount']);
        $this->assertSame(100.0, $assetA->fresh()->accumulatedDepreciation());
        $this->assertSame(100.0, $assetB->fresh()->accumulatedDepreciation());
        $this->assertSame(-200.0, Account::where('code', Ledger::ACCUMULATED_DEPRECIATION)->first()->balance());
        $this->assertSame(200.0, Account::where('code', Ledger::DEPRECIATION_EXPENSE)->first()->balance());
    }

    public function test_the_same_month_cannot_be_posted_twice(): void
    {
        $this->depreciation->registerAsset([
            'name' => 'أصل', 'purchase_date' => '2026-01-01', 'cost' => 1200, 'salvage_value' => 0, 'useful_life_months' => 12,
        ]);

        $this->depreciation->postMonthlyDepreciation('2026-09');

        $this->expectException(RuntimeException::class);
        $this->depreciation->postMonthlyDepreciation('2026-09');
    }

    public function test_depreciation_stops_once_the_asset_is_fully_depreciated(): void
    {
        $asset = $this->depreciation->registerAsset([
            'name' => 'أصل قصير العمر', 'purchase_date' => '2025-09-01', 'cost' => 100, 'salvage_value' => 0, 'useful_life_months' => 1,
        ]);

        $this->depreciation->postMonthlyDepreciation('2026-09');

        $this->assertTrue($asset->fresh()->isFullyDepreciated());
        $this->assertSame(0.0, $asset->fresh()->bookValue());
        $this->assertSame(100.0, $asset->fresh()->accumulatedDepreciation());

        $this->expectException(RuntimeException::class);
        $this->depreciation->postMonthlyDepreciation('2026-10');
    }

    public function test_disposed_assets_are_skipped(): void
    {
        $asset = $this->depreciation->registerAsset([
            'name' => 'أصل مستبعد', 'purchase_date' => '2026-01-01', 'cost' => 1200, 'salvage_value' => 0, 'useful_life_months' => 12,
        ]);

        $this->depreciation->disposeAsset($asset);

        $this->expectException(RuntimeException::class);
        $this->depreciation->postMonthlyDepreciation('2026-09');
    }
}
