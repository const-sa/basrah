<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * حساب المدير الذي يُفتح به النظام أول مرة.
 *
 * تركيبٌ جديد لا بدّ له من بابٍ يُدخل منه، فيُنشأ هذا الحساب إن لم يكن له
 * نظير. وهو حسابٌ حقيقي لا حسابُ تجربة (is_demo)، فلا تمسّه هجرةُ إزالة
 * حسابات التجربة.
 *
 * ولا يُكتب فوق حسابٍ قائم: المدير الذي غيّر كلمته — أو اسمه أو صلاحياته —
 * فعل ذلك عن قصد، وإعادةُ البذر بعده كانت تُرجعه إلى «123456» من حيث لا
 * يدري. فالبذرة تُنشئ ولا تُعدّل، والتغيير بعدها من الشاشة: الملف الشخصي
 * للاسم والبريد، و«كلمة المرور» لتبديلها.
 *
 * والبريدُ والكلمة يُقرآن من البيئة إن كُتبا فيها (ADMIN_EMAIL و
 * ADMIN_PASSWORD)، فيُركَّب الخادم بحسابٍ خاصٍّ به لا بحسابٍ معلوم للناس.
 */
class AdminUserSeeder extends Seeder
{
    public const DEFAULT_EMAIL = 'admin@admin.com';

    public const DEFAULT_PASSWORD = '123456';

    public function run(): void
    {
        $email = (string) (env('ADMIN_EMAIL') ?: self::DEFAULT_EMAIL);

        // الحساب القائم يُترك كما هو — كلمتُه كلمتُه، ودورُه دورُه.
        if (User::withTrashed()->where('email', $email)->exists()) {
            return;
        }

        User::create([
            'name' => 'مدير النظام',
            'email' => $email,
            'password' => Hash::make((string) (env('ADMIN_PASSWORD') ?: self::DEFAULT_PASSWORD)),
            'role_id' => Role::where('slug', 'super-admin')->value('id'),
            'is_active' => true,
            'has_all_units' => true,
            'email_verified_at' => now(),
        ]);
    }
}
