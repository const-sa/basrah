<?php

namespace App\Models;

use App\Support\BookingTimes;
use App\Support\Vat;
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
        // أوقات الحجز — فترات اليوم وأوقات الشاليه
        'booking_periods', 'chalet_check_in_time', 'chalet_check_out_time', 'chalet_max_nights',
        // الضريبة والسجل
        'tax_enabled', 'tax_number', 'tax_rate', 'commercial_register',
        // الواتساب
        'wa_enabled', 'wa_instance_id', 'wa_access_token',
        'wa_number', 'wa_connected_at',
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
            'wa_connected_at' => 'datetime',
            'booking_periods' => 'array',
            'chalet_max_nights' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Booking hours and the VAT rule are memoised for the request; a save
        // must not leave the old ones in play for the rest of it.
        static::saved(function () {
            app()->forgetInstance(BookingTimes::class);
            Vat::forget();
        });
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
            $settings = new self;
            $settings->id = 1;
            $settings->save();
        }

        return $settings;
    }
}
