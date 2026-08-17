<?php

namespace App\Models;

use App\Support\FieldLabels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صفّ في السجل الرقابي — عمليةٌ حساسة واحدة كما وقعت.
 */
class AuditLog extends Model
{
    /** السجل لا يُعدَّل بعد كتابته، فلا وقت تعديل له. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'actor_name', 'event',
        'auditable_type', 'auditable_id', 'auditable_label',
        'old_values', 'new_values',
        'ip_address', 'url', 'method', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public const EVENTS = [
        'created' => 'إنشاء',
        'updated' => 'تعديل',
        'deleted' => 'حذف',
        'restored' => 'استرجاع',
    ];

    /**
     * الأنواع الخاضعة للرقابة: الصنف => اسمه العربي.
     *
     * القائمة هنا لا في كل نموذج، لأن ما يُراقَب قرار إداري يُراجَع في موضع
     * واحد. وإضافة نوع لاحقًا سطرٌ هنا فقط.
     */
    public const SUBJECTS = [
        Booking::class => 'حجز',
        BookingPayment::class => 'دفعة حجز',
        Contract::class => 'عقد',
        ContractTemplate::class => 'نموذج عقد',
        Client::class => 'عميل',
        Unit::class => 'وحدة',
        UnitSection::class => 'قسم وحدة',
        UnitPrice::class => 'تسعيرة',
        Voucher::class => 'سند',
        JournalEntry::class => 'قيد محاسبي',
        Account::class => 'حساب',
        Sale::class => 'فاتورة بيع',
        Item::class => 'صنف',
        Employee::class => 'ملف موظف',
        Payroll::class => 'مسيّر رواتب',
        Advance::class => 'سلفة',
        Bonus::class => 'مكافأة',
        User::class => 'مستخدم',
        Role::class => 'دور وصلاحيات',
        Setting::class => 'الإعدادات العامة',
    ];

    /**
     * الحقول التي لا تُكتب في السجل مهما كان النموذج.
     *
     * كلمة المرور ورموز الوصول لا تُنسخ إلى جدول يُقرأ للمراجعة: السجل يجب
     * أن يبيّن أن الرمز تغيّر، لا أن يحفظ قيمته الجديدة نسخةً ثانية.
     */
    public const REDACTED = [
        'password', 'remember_token', 'wa_access_token', 'wa_instance_id',
        'api_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventLabel(): string
    {
        return self::EVENTS[$this->event] ?? $this->event;
    }

    public function subjectLabel(): string
    {
        return self::SUBJECTS[$this->auditable_type] ?? class_basename($this->auditable_type);
    }

    /**
     * الحقول التي تغيّرت فعلًا، مقرونةً بقيمتها قبل وبعد.
     *
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        $fields = array_values(array_unique([...array_keys($old), ...array_keys($new)]));

        return array_map(fn (string $field) => [
            'field' => $field,
            'label' => FieldLabels::for($field),
            'old' => $old[$field] ?? null,
            'new' => $new[$field] ?? null,
        ], $fields);
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        return $type ? $query->where('auditable_type', $type) : $query;
    }
}
