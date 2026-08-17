<?php

namespace App\Observers;

use App\Models\Booking;
use App\Services\ContractService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * توليد عقد الحجز تلقائيًا من نموذج العقد المعتمد.
 *
 * العقد كان لا يُنشأ إلا بطلبٍ من شاشة العقود، فيعيش الحجز بلا سند مكتوب
 * حتى يتذكّره أحد. توليده مع الحجز يجعل العقد قاعدةً لا استثناءً، ويبقى
 * زرّ التوليد في السجل للحجوزات القديمة أو لمن فشل توليدها.
 */
class BookingObserver
{
    /**
     * الأقسام تُربط بالحجز بعد إنشائه، والدفعة الأولى تُسجَّل معه في
     * المعاملة نفسها. لذلك يُؤجَّل العقد إلى ما بعد الاعتماد، وإلا خرج
     * بنطاقٍ فارغ ومبالغ غير نهائية.
     */
    public bool $afterCommit = true;

    public function __construct(private readonly ContractService $contracts) {}

    public function created(Booking $booking): void
    {
        // الحجز الملغى لا سند له، والعقد لا يُكرَّر على حجز واحد.
        if ($booking->status === 'cancelled' || $booking->contracts()->exists()) {
            return;
        }

        try {
            $booking->refresh()->loadMissing(['unit', 'client', 'sections']);

            $this->contracts->generate($booking, null, $booking->created_by);
        } catch (Throwable $e) {
            // غياب القالب الفعّال — أو أي عطل في التوليد — لا يُسقط الحجز:
            // الحجز هو الأصل، والعقد يبقى قابلًا للتوليد يدويًا من السجل.
            Log::warning("تعذّر توليد عقد الحجز {$booking->reference}: {$e->getMessage()}");
        }
    }
}
