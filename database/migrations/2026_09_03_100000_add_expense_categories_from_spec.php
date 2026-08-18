<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * بنود المصروف كما نصّ عليها البند التاسع من العرض المعتمد.
 *
 * البذرة تُنشئ الشجرة على القواعد الجديدة وحدها، وهذه المؤسسة تعمل على
 * قاعدة قائمة — فتُضاف البنود الناقصة هنا. والإضافة بالكود لا بالاسم، فمن
 * كان عنده الحساب لم يُنشأ له ثانٍ.
 *
 * و«كهرباء ومياه» يُقسَم: العرض يعدّهما بندين، وترشيد أحدهما لا يُقاس ما
 * داما في حسابٍ واحد. الحساب القديم يبقى بمعرّفه فلا تتيتّم قيوده، ويُعاد
 * تسميته «كهرباء» ويُفتح للمياه حسابٌ جديد.
 */
return new class extends Migration
{
    /**
     * الكود => الاسم، تحت «مصروفات عمومية» (5300).
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        '5320' => 'كهرباء',
        '5325' => 'مياه',
        '5330' => 'صيانة',
        '5340' => 'نظافة',
        '5350' => 'تسويق ودعاية',
        '5360' => 'إيجارات',
        '5370' => 'مشتريات',
        '5380' => 'خدمات',
        '5390' => 'إنترنت واتصالات',
        '5395' => 'قطع غيار',
    ];

    public function up(): void
    {
        $parentId = DB::table('accounts')->where('code', '5300')->value('id');

        // لا شجرة حسابات بعد (قاعدة جديدة تُبذر لاحقًا) فلا شيء يُضاف إليه.
        if (! $parentId) {
            return;
        }

        foreach (self::CATEGORIES as $code => $name) {
            $existing = DB::table('accounts')->where('code', $code)->first();

            if ($existing) {
                // الاسم وحده يُحدَّث: تغيير النوع أو الأب يعبث بحسابٍ له قيود.
                DB::table('accounts')->where('id', $existing->id)->update(['name' => $name]);

                continue;
            }

            DB::table('accounts')->insert([
                'code' => $code,
                'name' => $name,
                'type' => 'expense',
                'is_group' => false,
                'parent_id' => $parentId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('accounts')->where('code', '5320')->update(['name' => 'كهرباء ومياه']);

        // ما استُعمل في قيدٍ لا يُحذف — حذفه يُيتّم القيد ويُفسد التقارير.
        $used = DB::table('journal_lines')->distinct()->pluck('account_id');

        DB::table('accounts')
            ->whereIn('code', ['5325', '5370', '5380', '5390', '5395'])
            ->whereNotIn('id', $used)
            ->delete();
    }
};
