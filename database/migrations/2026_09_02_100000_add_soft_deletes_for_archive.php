<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأرشيف: لا شيء يُحذف من النظام حذفًا نهائيًا بضغطة زر.
 *
 * الحجوزات والعقود والفواتير والسندات والوحدات والأصناف كانت تُحذف حذفًا
 * ناعمًا أصلًا. هذه الهجرة تُعمّم القاعدة على بقية ما يُحذف من الشاشات:
 * العملاء والموظفون والمستخدمون والأدوار والحسابات وغيرها — فيصير المحذوف
 * كله في مكان واحد يُستعرض ويُسترجَع، والحذف النهائي قرارٌ منفصل يُتخذ من
 * شاشة الأرشيف وحدها.
 *
 * العمود مفهرس لأن شاشة الأرشيف تقرأ المحذوف فقط، وكل استعلام آخر في
 * النظام يمرّ على deleted_at بحكم النطاق العام للحذف الناعم.
 */
return new class extends Migration
{
    /**
     * الجداول التي تكسب الأرشفة هنا — وما كان مؤرشفًا من قبل ليس منها.
     *
     * @var list<string>
     */
    private const TABLES = [
        'accounts',
        'bonuses',
        'cities',
        'clients',
        'contract_templates',
        'departments',
        'employee_groups',
        'employees',
        'event_types',
        'facilities',
        'measure_units',
        'notification_templates',
        'packages',
        'payment_methods',
        'roles',
        'suppliers',
        'tickets',
        'users',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->softDeletes();
                $t->index('deleted_at', "{$table}_deleted_at_index");
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex("{$table}_deleted_at_index");
                $t->dropSoftDeletes();
            });
        }
    }
};
