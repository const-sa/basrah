<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * قالب عقد بحقول ديناميكية {{مفتاح}}.
 */
class ContractTemplate extends Model
{
    use SoftDeletes;

    /**
     * الحقول المتاحة للقالب — تُعرض للمحرّر ليعرف ما يستطيع إدراجه.
     */
    public const PLACEHOLDERS = [
        'contract_number' => 'رقم العقد',
        'contract_date' => 'تاريخ العقد',
        // The rental form prints «التاريخ» and «الموافق» together, so every
        // Gregorian key carries a Hijri twin.
        'contract_date_hijri' => 'تاريخ العقد هجريًا',
        'org_name' => 'اسم المؤسسة',
        'client_name' => 'اسم العميل',
        'client_mobile' => 'جوال العميل',
        'client_id_number' => 'رقم هوية العميل',
        'client_address' => 'عنوان العميل',
        // موضوع العقد يملؤه الموظف في العقد اليدوي (المسابح)، وفي عقد الحجز
        // يُملأ باسم الوحدة فيبقى القالب الواحد صالحًا للاثنين.
        'subject' => 'موضوع العقد',
        'booking_reference' => 'رقم الحجز',
        'unit_name' => 'اسم الوحدة',
        'sections' => 'الأقسام المحجوزة',
        'booking_date' => 'تاريخ الحجز',
        'booking_date_hijri' => 'تاريخ الحجز هجريًا',
        'days_count' => 'عدد الأيام (أو الليالي في الشاليه)',
        'duration_label' => 'مدة الحجز مكتوبة',
        'last_day_date' => 'تاريخ آخر يوم (أو الخروج)',
        'last_day_date_hijri' => 'تاريخ آخر يوم هجريًا',
        // The daily-rental form reads as a sentence — «تبدأ من يوم الخميس …
        // وقت الدخول الساعة …» — so day names and hours are their own fields.
        'check_in_day' => 'يوم الدخول',
        'check_out_day' => 'يوم الخروج',
        'check_in_time' => 'وقت الدخول',
        'check_out_time' => 'وقت الخروج',
        'period' => 'الفترة',
        'starts_at' => 'بداية الفترة',
        'ends_at' => 'نهاية الفترة',
        'guests_count' => 'عدد الضيوف',
        'total_amount' => 'الإجمالي',
        'total_amount_words' => 'الإجمالي كتابةً',
        // الضريبة داخل الإجمالي لا فوقه، فقالب العقد يذكرها تفصيلًا لا يزيدها.
        'subtotal' => 'الإجمالي قبل الضريبة',
        'tax_rate' => 'نسبة الضريبة',
        'tax_amount' => 'مبلغ الضريبة',
        'deposit_amount' => 'العربون',
        'remaining_amount' => 'المتبقي',
        'security_deposit' => 'مبلغ التأمين المسترد',
    ];

    protected $fillable = [
        'name', 'description', 'body', 'terms', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public static function defaultTemplate(): ?self
    {
        return static::where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<array{key: string, label: string, token: string}>
     */
    public static function placeholdersForView(): array
    {
        return collect(self::PLACEHOLDERS)
            ->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'token' => '{{'.$key.'}}'])
            ->values()->all();
    }
}
