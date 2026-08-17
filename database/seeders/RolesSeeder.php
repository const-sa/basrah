<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\SystemRegistry;
use Illuminate\Database\Seeder;

/**
 * أدوار مؤسسة ديوان البصرة (§الطبقة أ - بند 6):
 * مالك / محاسب / مشرف وحدة / كاشير.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        Role::updateOrCreate(
            ['slug' => 'super-admin'],
            [
                'name' => 'المالك',
                'description' => 'صلاحية كاملة على كل الأنظمة دون استثناء',
                'permissions' => SystemRegistry::permissionKeys(),
            ],
        );

        // المحاسب: المحاسبة والرواتب كاملة + اطلاع على الحجوزات والمبيعات دون تعديلها
        Role::updateOrCreate(
            ['slug' => 'accountant'],
            [
                'name' => 'محاسب',
                'description' => 'المحاسبة والرواتب كاملة، مع الاطلاع على الحجوزات والمبيعات',
                'permissions' => array_values(array_unique(array_merge(
                    SystemRegistry::systemPermissionKeys('accounting'),
                    SystemRegistry::systemPermissionKeys('hr'),
                    [
                        'dashboard.view', 'reports.view', 'reports.export',
                        'bookings.view', 'calendar.view', 'units.view',
                        'contracts.view', 'contracts.export',
                        'sales.view', 'sales.export', 'inventory.view',
                        'clients.view', 'clients.export', 'suppliers.view',
                    ],
                ))),
            ],
        );

        // مشرف وحدة: يدير حجوزات وعقود وحدته فقط — التقييد عبر جدول unit_user
        Role::updateOrCreate(
            ['slug' => 'unit-supervisor'],
            [
                'name' => 'مشرف وحدة',
                'description' => 'إدارة حجوزات وعقود الوحدات المسندة إليه فقط',
                'permissions' => [
                    'dashboard.view',
                    'units.view',
                    'calendar.view',
                    'bookings.view', 'bookings.create', 'bookings.edit',
                    'pricing.view',
                    'packages.view', 'packages.create', 'packages.edit',
                    'event_types.view', 'event_types.create', 'event_types.edit',
                    'addons.view', 'addons.create', 'addons.edit',
                    'contracts.view', 'contracts.create', 'contracts.send', 'contracts.export',
                    'contract_templates.view',
                    'whatsapp.view', 'whatsapp.send',
                    'clients.view', 'clients.create', 'clients.edit',
                    'tickets.view', 'tickets.create',
                    'reports.view',
                ],
            ],
        );

        // كاشير: شاشة البيع فقط، بلا حذف وبلا اطلاع محاسبي
        Role::updateOrCreate(
            ['slug' => 'cashier'],
            [
                'name' => 'كاشير',
                'description' => 'شاشة البيع والمخزون دون أي اطلاع محاسبي',
                'permissions' => [
                    'dashboard.view',
                    'pos.view', 'pos.create',
                    'items.view',
                    'inventory.view',
                    'sales.view', 'sales.create',
                    'clients.view', 'clients.create',
                ],
            ],
        );
    }
}
