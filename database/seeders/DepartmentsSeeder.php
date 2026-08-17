<?php

namespace Database\Seeders;

use App\Models\CostCenter;
use App\Models\Department;
use App\Models\MeasureUnit;
use Illuminate\Database\Seeder;

/**
 * أقسام المؤسسة (أنشطتها) ووحدات القياس.
 *
 * القسم نشاط تجاري مستقل بمخزونه ومركز تكلفته. قسم المسابح هو
 * القسم البائع (sells) فتُفتح عليه شاشة الفواتير.
 */
class DepartmentsSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            [
                'code' => 'POOLS',
                'name' => 'المسابح',
                'description' => 'بيع معدات المسابح وموادها وخدمات الصيانة والتركيب',
                'sells' => true,
                'sort_order' => 1,
            ],
            [
                'code' => 'VENUES',
                'name' => 'القاعات والشاليهات',
                'description' => 'تشغيل الوحدات القابلة للحجز',
                'sells' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'ADMIN',
                'name' => 'الإدارة',
                'description' => 'الإدارة والمالية والموارد البشرية',
                'sells' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($departments as $row) {
            $department = Department::updateOrCreate(
                ['code' => $row['code']],
                [...$row, 'is_active' => true],
            );

            // مركز تكلفة لكل قسم — به تُقاس ربحية النشاط
            CostCenter::forDepartment($department);
        }

        $units = [
            ['code' => 'PCS', 'name' => 'قطعة', 'symbol' => 'حبة', 'allows_fraction' => false],
            ['code' => 'M', 'name' => 'متر طولي', 'symbol' => 'م.ط', 'allows_fraction' => true],
            ['code' => 'M2', 'name' => 'متر مربع', 'symbol' => 'م²', 'allows_fraction' => true],
            ['code' => 'M3', 'name' => 'متر مكعب', 'symbol' => 'م³', 'allows_fraction' => true],
            ['code' => 'HR', 'name' => 'ساعة', 'symbol' => 'س', 'allows_fraction' => true],
            ['code' => 'KG', 'name' => 'كيلوجرام', 'symbol' => 'كجم', 'allows_fraction' => true],
            ['code' => 'L', 'name' => 'لتر', 'symbol' => 'ل', 'allows_fraction' => true],
            ['code' => 'BOX', 'name' => 'كرتون', 'symbol' => null, 'allows_fraction' => false],
            ['code' => 'ROLL', 'name' => 'لفة', 'symbol' => null, 'allows_fraction' => false],
            ['code' => 'SET', 'name' => 'طقم', 'symbol' => null, 'allows_fraction' => false],
            ['code' => 'VISIT', 'name' => 'زيارة', 'symbol' => null, 'allows_fraction' => false],
        ];

        foreach ($units as $i => $unit) {
            MeasureUnit::updateOrCreate(
                ['code' => $unit['code']],
                [...$unit, 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
