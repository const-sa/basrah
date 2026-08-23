<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\CostCenter;
use App\Models\ExpenseCategory;
use App\Models\Treasury;
use App\Models\Unit;
use App\Services\Accounting\Ledger;
use Illuminate\Database\Seeder;

/**
 * شجرة حسابات ابتدائية + مركز تكلفة لكل وحدة + خزينة نقدية وبنكية.
 *
 * الأكواد هنا هي نفسها ثوابت Ledger — تغييرها يكسر القيود التلقائية.
 */
class AccountsSeeder extends Seeder
{
    /**
     * [الكود, الاسم, النوع, تجميعي؟, كود الأب]
     */
    private const TREE = [
        ['1000', 'الأصول', 'asset', true, null],
        ['1100', 'النقدية وما في حكمها', 'asset', true, '1000'],
        [Ledger::CASH, 'الصندوق', 'asset', false, '1100'],
        [Ledger::BANK, 'البنك', 'asset', false, '1100'],
        ['1200', 'المدينون', 'asset', true, '1000'],
        [Ledger::RECEIVABLES, 'ذمم العملاء', 'asset', false, '1200'],
        ['1300', 'المخزون', 'asset', true, '1000'],
        [Ledger::INVENTORY, 'مخزون البضاعة', 'asset', false, '1300'],

        ['2000', 'الالتزامات', 'liability', true, null],
        ['2100', 'الدائنون', 'liability', true, '2000'],
        [Ledger::PAYABLES, 'ذمم الموردين', 'liability', false, '2100'],
        ['2200', 'إيرادات مقدَّمة', 'liability', true, '2000'],
        [Ledger::UNEARNED_REVENUE, 'عرابين حجوزات غير مكتسبة', 'liability', false, '2200'],
        ['2300', 'مستحقات الموظفين', 'liability', true, '2000'],
        [Ledger::SALARIES_PAYABLE, 'رواتب مستحقة', 'liability', false, '2300'],
        // A security deposit is not unearned revenue: a booking deposit is on
        // its way to becoming revenue, a security deposit is on its way back
        // to the guest unless damage is taken out of it.
        ['2400', 'تأمينات مستلمة', 'liability', true, '2000'],
        [Ledger::REFUNDABLE_DEPOSITS, 'تأمينات حجوزات مستردة', 'liability', false, '2400'],

        ['3000', 'حقوق الملكية', 'equity', true, null],
        ['3100', 'رأس المال', 'equity', false, '3000'],
        ['3200', 'الأرباح المحتجزة', 'equity', false, '3000'],

        ['4000', 'الإيرادات', 'revenue', true, null],
        ['4100', 'إيرادات التشغيل', 'revenue', true, '4000'],
        [Ledger::BOOKING_REVENUE, 'إيرادات الحجوزات', 'revenue', false, '4100'],
        [Ledger::SALES_REVENUE, 'إيرادات المبيعات', 'revenue', false, '4100'],
        ['4130', 'إيرادات الخدمات الإضافية', 'revenue', false, '4100'],

        ['5000', 'المصروفات', 'expense', true, null],
        ['5100', 'تكلفة المبيعات', 'expense', true, '5000'],
        [Ledger::COGS, 'تكلفة البضاعة المباعة', 'expense', false, '5100'],
        ['5200', 'مصروفات الرواتب', 'expense', true, '5000'],
        [Ledger::SALARIES_EXPENSE, 'الرواتب والأجور', 'expense', false, '5200'],
        ['5300', 'مصروفات عمومية', 'expense', true, '5000'],
        [Ledger::GENERAL_EXPENSE, 'مصروفات متنوعة', 'expense', false, '5300'],
        // بنود المصروف كما نصّ عليها البند التاسع من العرض المعتمد. الكهرباء
        // والمياه فُصلتا لأن العرض يعدّهما بندين، ولأن ترشيد أحدهما لا يُقاس
        // ما داما في حسابٍ واحد.
        ['5320', 'كهرباء', 'expense', false, '5300'],
        ['5325', 'مياه', 'expense', false, '5300'],
        ['5330', 'صيانة', 'expense', false, '5300'],
        ['5340', 'نظافة', 'expense', false, '5300'],
        ['5350', 'تسويق ودعاية', 'expense', false, '5300'],
        ['5360', 'إيجارات', 'expense', false, '5300'],
        ['5370', 'مشتريات', 'expense', false, '5300'],
        ['5380', 'خدمات', 'expense', false, '5300'],
        ['5390', 'إنترنت واتصالات', 'expense', false, '5300'],
        ['5395', 'قطع غيار', 'expense', false, '5300'],
    ];

    public function run(): void
    {
        foreach (self::TREE as [$code, $name, $type, $isGroup, $parentCode]) {
            Account::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'is_group' => $isGroup,
                    'parent_id' => $parentCode ? Account::where('code', $parentCode)->value('id') : null,
                    'is_active' => true,
                ],
            );
        }

        // مركز تكلفة لكل وحدة + مركز المحل
        foreach (Unit::all() as $unit) {
            CostCenter::forUnit($unit);
        }
        CostCenter::general();

        Treasury::updateOrCreate(
            ['name' => 'الصندوق الرئيسي'],
            [
                'type' => 'cash',
                'account_id' => Account::where('code', Ledger::CASH)->value('id'),
                'opening_balance' => 0,
                'is_active' => true,
            ],
        );

        Treasury::updateOrCreate(
            ['name' => 'الحساب البنكي'],
            [
                'type' => 'bank',
                'account_id' => Account::where('code', Ledger::BANK)->value('id'),
                'opening_balance' => 0,
                'is_active' => true,
            ],
        );

        // أنواع المصروف (§9) — بعد الشجرة لأن كل نوع يشير إلى حسابه فيها.
        ExpenseCategory::seedDefaults();
    }
}
