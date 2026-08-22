<?php

use App\Support\SystemRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل صلاحيات المجموعات إلى مفاتيح الأقسام الجديدة.
 *
 * كان مفتاحٌ واحد (bookings.*, units.*, calendar.*) يخدم القاعات والشاليهات
 * معًا، فصار لكل نشاط مفاتيحه. المجموعات المحفوظة تُترجم هنا: من ملك المفتاح
 * القديم يملك مفتاحَي النشاطين — فلا تفقد مجموعةٌ قائمةٌ صلاحياتها بعد
 * الترقية، وللإدارة أن تنزع بعدها ما لا تريده لكل نشاط على حدة.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->orderBy('id')->each(function ($role) {
            $permissions = json_decode($role->permissions ?? '[]', true);

            if (! is_array($permissions)) {
                return;
            }

            // المالك يُمنح كل شيء دائمًا، فترجمة قائمته لا معنى لها.
            $migrated = $role->slug === 'super-admin'
                ? SystemRegistry::permissionKeys()
                : SystemRegistry::migrateLegacyKeys($permissions);

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values($migrated), JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    /**
     * لا تراجُع: المفاتيح القديمة أخشن من الجديدة، فالعودة إليها تعيد منح
     * النشاطين لمن مُنح أحدهما. من أراد الرجوع استعاد نسخة القاعدة.
     */
    public function down(): void {}
};
