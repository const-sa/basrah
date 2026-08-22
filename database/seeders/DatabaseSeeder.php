<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
        ]);

        $superAdmin = Role::where('slug', 'super-admin')->first();

        User::updateOrCreate(
            ['email' => 'admin@admin.com'],
            [
                'name' => 'مدير النظام',
                'password' => Hash::make('123456'),
                'role_id' => $superAdmin?->id,
                'is_active' => true,
                'has_all_units' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
