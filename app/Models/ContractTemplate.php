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
        'org_name' => 'اسم المؤسسة',
        'client_name' => 'اسم العميل',
        'client_mobile' => 'جوال العميل',
        'client_id_number' => 'رقم هوية العميل',
        'booking_reference' => 'رقم الحجز',
        'unit_name' => 'اسم الوحدة',
        'sections' => 'الأقسام المحجوزة',
        'booking_date' => 'تاريخ الحجز',
        'days_count' => 'عدد الأيام (أو الليالي في الشاليه)',
        'last_day_date' => 'تاريخ آخر يوم (أو الخروج)',
        'period' => 'الفترة',
        'starts_at' => 'بداية الفترة',
        'ends_at' => 'نهاية الفترة',
        'guests_count' => 'عدد الضيوف',
        'total_amount' => 'الإجمالي',
        'deposit_amount' => 'العربون',
        'remaining_amount' => 'المتبقي',
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
