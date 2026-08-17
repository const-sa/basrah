<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * طرق الدفع الابتدائية — نفس ما كان في ثوابت الكود قبل الجدول.
 *
 * الصفوف تُقرأ من `PaymentMethod::defaults()` لتبقى في موضع واحد تشاركه
 * الهجرة. والبذر بالكود مفتاحًا حتى لا يُكرّر ما أنشأته الهجرة، ولا يُطمس
 * ما عدّله المستخدم على طريقة قائمة.
 */
class PaymentMethodsSeeder extends Seeder
{
    public function run(): void
    {
        $existing = DB::table('payment_methods')->pluck('code')->all();

        $missing = collect(PaymentMethod::defaults())
            ->reject(fn (array $row) => in_array($row['code'], $existing, true))
            ->values()
            ->all();

        if ($missing !== []) {
            // إدخال الناقص وحده لا تحديث الكل: المستخدم قد يكون أعاد تسمية
            // طريقة أو غيّر حساب إيداعها، وإعادة البذر لا يجوز أن تنقض ذلك.
            // والإدخال بـ DB لا بـ Eloquent لأن is_system خارج fillable عمدًا.
            DB::table('payment_methods')->insert($missing);
        }
    }
}
