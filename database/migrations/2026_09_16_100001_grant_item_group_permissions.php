<?php

use App\Support\SystemRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * منح صلاحيات مجموعات الأصناف للمجموعات القائمة.
 *
 * المفتاح جديد، فلا مجموعة تملكه بعد الترقية ولو كانت تدير الأصناف كلها.
 * ومجموعات الأصناف اختصار لاختيار الأصناف لا بابٌ جديد على بيانات أخرى،
 * فمن ملك فعلًا على `items` يملك نظيره على `item_groups` — ومن اكتفى
 * بالاطلاع على الأصناف يكتفي بالاطلاع على مجموعاتها.
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

            // المالك يُمنح كل شيء دائمًا، فلا حاجة لاشتقاق مفاتيحه.
            if ($role->slug === 'super-admin') {
                $granted = SystemRegistry::permissionKeys();
            } else {
                $granted = $permissions;

                foreach (['view', 'create', 'edit', 'delete'] as $action) {
                    if (in_array("items.{$action}", $permissions, true)) {
                        $granted[] = "item_groups.{$action}";
                    }
                }

                // من يبيع على الكاشير يحتاج رؤية المجموعات ولو لم يُسنَد إليه
                // ملف الأصناف — وهي أصلًا لا تُظهر إلا ما يظهره اختيار الصنف.
                if (in_array('pos.view', $permissions, true)) {
                    $granted[] = 'item_groups.view';
                }

                $granted = array_values(array_unique($granted));
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($granted, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    /**
     * نزع المفاتيح الجديدة وحدها — لا يمسّ ما كان قبلها.
     */
    public function down(): void
    {
        DB::table('roles')->orderBy('id')->each(function ($role) {
            $permissions = json_decode($role->permissions ?? '[]', true);

            if (! is_array($permissions)) {
                return;
            }

            $kept = array_values(array_filter(
                $permissions,
                fn ($key) => ! str_starts_with((string) $key, 'item_groups.'),
            ));

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($kept, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }
};
