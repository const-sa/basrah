<?php

namespace App\Models;

use App\Support\BookingTimes;
use App\Support\Letterhead;
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
        // Social accounts — a handle or a full profile link
        'instagram', 'tiktok', 'snapchat',
        // هوية نشاط المسابح — جهة مستقلة بكامل بياناتها (راجع App\Support\Letterhead)
        'pools_name', 'pools_logo_path', 'pools_phone', 'pools_whatsapp', 'pools_email', 'pools_address',
        'pools_tax_number', 'pools_commercial_register', 'pools_manager_name',
        'pools_signature_path', 'pools_stamp_path',
        // أوقات الحجز — فترات اليوم وأوقات الشاليه
        'booking_periods', 'chalet_check_in_time', 'chalet_check_out_time', 'chalet_max_nights',
        // الضريبة والسجل
        'tax_enabled', 'tax_number', 'tax_rate', 'commercial_register',
        // الواتساب
        'wa_enabled', 'wa_instance_id', 'wa_access_token',
        'wa_number', 'wa_connected_at',
        'wa_welcome_enabled',
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
     * The pools activity's letterhead — its own identity only. The activity is
     * a separate business, so a blank field stays blank rather than borrowing
     * the Diwan's; see App\Support\Letterhead.
     *
     * @return array{name: string, logo_path: string|null, phone: string|null}
     */
    public function poolsLetterhead(): array
    {
        return Letterhead::raw(true, $this);
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
