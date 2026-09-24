<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * سجل رسالة واتساب — أساس احتساب استهلاك المحادثات (§4.4).
 */
class WhatsappMessage extends Model
{
    public const CATEGORIES = [
        'utility' => 'خدمية',
        'authentication' => 'مصادقة',
        'marketing' => 'تسويقية',
    ];

    public const PURPOSES = [
        'booking_confirm' => 'تأكيد حجز',
        'reminder' => 'تذكير بالموعد',
        'balance_reminder' => 'تذكير بالمتبقي',
        'contract' => 'عقد',
        'invoice' => 'فاتورة',
        'payment' => 'إشعار سداد',
        'receipt' => 'سند قبض',
        'cancellation' => 'إشعار إلغاء',
        'welcome' => 'ترحيب',
        'other' => 'أخرى',
    ];

    protected $fillable = [
        'to_number', 'body', 'category', 'purpose', 'status', 'error',
        'sent_at', 'related_type', 'related_id', 'sent_by', 'whatsapp_account_id',
    ];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /** الرقم الذي خرجت منه — فارغ للبوابة العامة. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class, 'whatsapp_account_id');
    }

    /**
     * عدد المحادثات المحتسَبة خلال فترة.
     *
     * Meta تسعّر لكل *محادثة* مدتها 24 ساعة لا لكل رسالة، فالرسائل المتعددة
     * للرقم نفسه في اليوم نفسه محادثة واحدة. هذا ما يُقارن بحد التجديد.
     */
    public static function conversationCount(?string $from = null, ?string $to = null, ?int $accountId = null): int
    {
        // التجميع بـ GROUP BY ثم عدّ المجموعات — بديل محمول عن
        // COUNT(DISTINCT a, b) التي لا تدعمها إلا MySQL.
        return static::query()
            ->where('status', 'sent')
            // لكل رقم قسمٍ اشتراكه، فمحادثاته تُعدّ على حدة.
            ->when($accountId, fn ($q) => $q->where('whatsapp_account_id', $accountId))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->groupBy('to_number', 'conversation_day')
            ->selectRaw('to_number, DATE(created_at) AS conversation_day')
            ->get()
            ->count();
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', 'sent');
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? $this->purpose;
    }
}
