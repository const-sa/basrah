<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientsSeeder extends Seeder
{
    public function run(): void
    {
        // العميل النقدي أولًا — عليه تُحمل فواتير البيع بلا عميل محدد.
        Client::walkIn();

        $clients = [
            ['name' => 'محمد العبدالله', 'mobile' => '0501234567', 'email' => 'mohammed@example.com', 'city' => 'الرياض', 'national_id' => '1012345678', 'is_taxable' => true, 'tax_number' => '300000000000003', 'tax_address' => 'الرياض - حي العليا', 'is_active' => true],
            ['name' => 'شركة الأفق للتجارة', 'mobile' => '0533112233', 'email' => 'info@ofok.com', 'city' => 'جدة', 'is_taxable' => true, 'tax_number' => '311111111100003', 'tax_address' => 'جدة - حي الروضة', 'is_active' => true],
            ['name' => 'سارة القحطاني', 'mobile' => '0555667788', 'email' => 'sara@example.com', 'city' => 'الدمام', 'national_id' => '1023456789', 'is_taxable' => false, 'tax_number' => null, 'tax_address' => null, 'is_active' => true],
            ['name' => 'مؤسسة النخبة', 'mobile' => '0544778899', 'email' => 'nokhba@example.com', 'city' => 'مكة المكرمة', 'is_taxable' => true, 'tax_number' => '322222222200003', 'tax_address' => 'مكة - العزيزية', 'is_active' => true],
            ['name' => 'خالد الشمري', 'mobile' => '0567889900', 'email' => 'khalid@example.com', 'city' => 'المدينة المنورة', 'is_taxable' => false, 'tax_number' => null, 'tax_address' => null, 'is_active' => false],
            ['name' => 'نورة الدوسري', 'mobile' => '0512349876', 'email' => 'noura@example.com', 'city' => 'الطائف', 'is_taxable' => false, 'tax_number' => null, 'tax_address' => null, 'is_active' => true],
            ['name' => 'شركة المستقبل الرقمي', 'mobile' => '0503216549', 'email' => 'sales@future.com', 'city' => 'الرياض', 'is_taxable' => true, 'tax_number' => '333333333300003', 'tax_address' => 'الرياض - حي الملقا', 'is_active' => true],
            ['name' => 'عبدالرحمن الغامدي', 'mobile' => '0598765432', 'email' => 'abdulrahman@example.com', 'city' => 'أبها', 'is_taxable' => false, 'tax_number' => null, 'tax_address' => null, 'is_active' => true],
            ['name' => 'مطاعم الذواقة', 'mobile' => '0521472583', 'email' => 'contact@thawaqa.com', 'city' => 'الخبر', 'is_taxable' => true, 'tax_number' => '344444444400003', 'tax_address' => 'الخبر - الكورنيش', 'is_active' => false],
            ['name' => 'ريم العتيبي', 'mobile' => '0577418529', 'email' => 'reem@example.com', 'city' => 'بريدة', 'is_taxable' => false, 'tax_number' => null, 'tax_address' => null, 'is_active' => true],
        ];

        foreach ($clients as $data) {
            Client::updateOrCreate(
                ['mobile' => $data['mobile']],
                $data,
            );
        }
    }
}
