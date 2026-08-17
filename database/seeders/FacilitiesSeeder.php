<?php

namespace Database\Seeders;

use App\Models\Facility;
use Illuminate\Database\Seeder;

/**
 * مرافق ابتدائية — تُدار بعدها من شاشة المرافق.
 */
class FacilitiesSeeder extends Seeder
{
    public function run(): void
    {
        $facilities = [
            ['name' => 'مسبح', 'icon' => 'Waves', 'description' => 'مسبح خاص أو مشترك بين الأقسام'],
            ['name' => 'مطبخ', 'icon' => 'ChefHat', 'description' => 'مطبخ مجهّز'],
            ['name' => 'موقف سيارات', 'icon' => 'Car'],
            ['name' => 'مدخل خاص', 'icon' => 'DoorOpen', 'description' => 'مدخل مستقل يرفع خصوصية القسم'],
            ['name' => 'جلسة خارجية', 'icon' => 'Trees'],
            ['name' => 'ملعب', 'icon' => 'Volleyball'],
            ['name' => 'إنترنت', 'icon' => 'Wifi'],
            ['name' => 'شاشة عرض', 'icon' => 'Tv'],
            ['name' => 'تكييف', 'icon' => 'Wind'],
            ['name' => 'ركن شواء', 'icon' => 'Flame'],
            ['name' => 'ألعاب أطفال', 'icon' => 'Baby'],
        ];

        foreach ($facilities as $i => $facility) {
            Facility::updateOrCreate(
                ['name' => $facility['name']],
                [...$facility, 'sort_order' => $i, 'is_active' => true],
            );
        }
    }
}
