<?php

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitiesSeeder extends Seeder
{
    public function run(): void
    {
        $cities = [
            'الرياض', 'جدة', 'مكة المكرمة', 'المدينة المنورة', 'الدمام',
            'الخبر', 'الظهران', 'الطائف', 'تبوك', 'بريدة',
            'خميس مشيط', 'أبها', 'حائل', 'نجران', 'جازان',
            'ينبع', 'الجبيل', 'القطيف', 'الأحساء', 'عرعر',
        ];

        foreach ($cities as $name) {
            City::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
