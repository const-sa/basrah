<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            RolesSeeder::class,
            FacilitiesSeeder::class,
            DepartmentsSeeder::class,
            UnitsSeeder::class,
            BookingSetupSeeder::class,
            PackagesSeeder::class,
            EventTypesSeeder::class,
            AccountsSeeder::class,
            PaymentMethodsSeeder::class,
            CatalogSeeder::class,
            ContractTemplateSeeder::class,
            NotificationTemplatesSeeder::class,
            CitiesSeeder::class,
            ClientsSeeder::class,
            // آخرًا لأنه يحتاج الأدوار مبذورةً قبله.
            AdminUserSeeder::class,
        ]);
    }
}
