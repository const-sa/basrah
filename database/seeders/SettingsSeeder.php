<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\PoolsLetterhead;
use Illuminate\Database\Seeder;

/**
 * تثبيت هوية المنشأة: الشعار والأيقونة.
 *
 * الشعار ملفٌّ ثابتٌ في المستودع لا رفعٌ يدويّ، حتى تُنشأ أي بيئةٍ جديدة
 * (تطوير، اختبار، خادم بديل) وهي تحمل الشعار نفسه — كان يُرفع يدويًا في
 * كل بيئةٍ فتظهر إحداها بلا شعار.
 */
class SettingsSeeder extends Seeder
{
    /** مسار الشعار نسبيًا إلى public/ — يُخدم مباشرةً دون symlink. */
    public const LOGO_PATH = 'images/brand/logo.png';

    /** نسخة مربّعة من الشعار: التبويب والجوال يقصّان الصورة الطولية. */
    public const ICON_PATH = 'images/brand/icon.png';

    public function run(): void
    {
        $settings = Setting::current();

        // The pools activity trades under its own name and phone — pinned here
        // so a new install carries them without anyone typing them in.
        if (blank($settings->pools_name)) {
            $settings->pools_name = PoolsLetterhead::NAME;
            $settings->pools_phone = $settings->pools_phone ?: PoolsLetterhead::PHONE;
        }

        if (! is_file(public_path(self::LOGO_PATH))) {
            $settings->save();
            $this->command?->warn('لم يُعثر على ملف الشعار: public/'.self::LOGO_PATH.' — ضَعه ثم أعِد التشغيل.');

            return;
        }

        $settings->logo_path = self::LOGO_PATH;

        // الأيقونة تتبع الشعار ما لم تُرفع أيقونةٌ خاصة: مصدرٌ واحدٌ للهوية.
        if (blank($settings->favicon_path) && is_file(public_path(self::ICON_PATH))) {
            $settings->favicon_path = self::ICON_PATH;
        }

        $settings->save();
    }
}
