<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    /**
     * الأعمدة القابلة للإسناد الجماعي (بدل $guarded = [] المفتوح بالكامل).
     *
     * @var list<string>
     */
    protected $fillable = [
        // الهوية والتواصل
        'business_name', 'logo_path', 'favicon_path',
        'phone', 'whatsapp', 'email', 'address',
        // الضريبة والسجل
        'tax_enabled', 'tax_number', 'tax_rate', 'commercial_register',
        // الواتساب
        'wa_enabled', 'wa_instance_id', 'wa_access_token',
        'wa_welcome_enabled', 'wa_welcome_template',
        // المدراء والتواقيع والختم
        'manager_name', 'manager_signature_path',
        'finance_manager_name', 'finance_manager_signature_path',
        'stamp_path',
    ];

    protected function casts(): array
    {
        return [
            'tax_enabled' => 'boolean',
            'tax_rate' => 'decimal:2',
            'wa_enabled' => 'boolean',
            'wa_welcome_enabled' => 'boolean',
        ];
    }

    /**
     * الحصول على صفّ الإعدادات الوحيد (ينشئه إن لم يوجد).
     */
    public static function current(): self
    {
        // صفّ الإعدادات صِفٌ وحيد بالمعرّف 1. نُثبِّت المعرّف مباشرةً
        // (لا عبر الإسناد الجماعي) لأن id ليس ضمن $fillable.
        $settings = static::query()->find(1);

        if (! $settings) {
            $settings = new self();
            $settings->id = 1;
            $settings->save();
        }

        return $settings;
    }
}
