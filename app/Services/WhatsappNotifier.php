<?php

namespace App\Services;

use App\Jobs\SendWhatsappMessage;
use App\Models\Booking;
use App\Models\Contract;
use App\Models\WhatsappMessage;
use Illuminate\Database\Eloquent\Model;

/**
 * إرسال رسائل واتساب الخدمية مع تسجيلها (§3.1).
 *
 * كل رسالة تُسجَّل قبل إرسالها — بلا سجل لا سبيل لإثبات استهلاك
 * المحادثات عند مراجعة بند التجديد السنوي (§4.4).
 *
 * الرسائل التسويقية مستثناة صراحة من النطاق، فلا دالة لها هنا.
 */
class WhatsappNotifier
{
    /**
     * تسجيل رسالة ودفعها للطابور.
     */
    public function send(
        ?string $number,
        string $body,
        string $purpose = 'other',
        ?Model $related = null,
        ?int $userId = null,
        ?string $mediaUrl = null,
    ): ?WhatsappMessage {
        $number = $this->normalize($number);

        if (! $number) {
            return null;
        }

        $message = WhatsappMessage::create([
            'to_number' => $number,
            'body' => $body,
            'category' => 'utility',
            'purpose' => $purpose,
            'status' => 'queued',
            'related_type' => $related ? $related::class : null,
            'related_id' => $related?->getKey(),
            'sent_by' => $userId,
        ]);

        SendWhatsappMessage::dispatch($number, $body, $mediaUrl);

        // البوابة تعمل في الطابور ولا تُرجع نتيجة فورية، فتُعلَّم كمُرسلة
        // ويُصحَّح الحال عند فشل المهمة نهائيًا.
        $message->update(['status' => 'sent', 'sent_at' => now()]);

        return $message;
    }

    /**
     * تأكيد حجز.
     */
    public function bookingConfirmed(Booking $booking, ?int $userId = null): ?WhatsappMessage
    {
        $booking->loadMissing(['unit', 'client']);

        $body = implode("\n", array_filter([
            'مرحبًا '.($booking->client?->name ?? '').'،',
            'تم تأكيد حجزكم رقم '.$booking->reference.'.',
            'الوحدة: '.($booking->unit?->name ?? '—'),
            'التاريخ: '.$booking->booking_date->toDateString().' — '.$booking->periodLabel(),
            'الإجمالي: '.number_format((float) $booking->total_amount, 2),
            $booking->remainingAmount() > 0
                ? 'المتبقي: '.number_format($booking->remainingAmount(), 2)
                : 'مسدَّد بالكامل.',
            'شكرًا لثقتكم.',
        ]));

        return $this->send($booking->client?->mobile, $body, 'booking_confirm', $booking, $userId);
    }

    /**
     * تذكير قبل الموعد.
     */
    public function bookingReminder(Booking $booking, ?int $userId = null): ?WhatsappMessage
    {
        $booking->loadMissing(['unit', 'client']);

        $body = implode("\n", array_filter([
            'تذكير بحجزكم رقم '.$booking->reference.'.',
            'الوحدة: '.($booking->unit?->name ?? '—'),
            'الموعد: '.$booking->booking_date->toDateString().' — '.$booking->periodLabel(),
            $booking->remainingAmount() > 0
                ? 'المتبقي عند الحضور: '.number_format($booking->remainingAmount(), 2)
                : null,
            'نتشرّف باستقبالكم.',
        ]));

        return $this->send($booking->client?->mobile, $body, 'reminder', $booking, $userId);
    }

    /**
     * إرسال العقد — مرفقًا بملفه PDF حين يُمرَّر رابطه.
     *
     * الرسالة لا تعد بمرفق لا تحمله: بلا رابط تُصاغ كإشعار بصدور العقد
     * ليراجعه العميل مع الموظف، وبه تُصاغ كإرسالٍ للمستند نفسه.
     */
    public function contract(Contract $contract, ?int $userId = null, ?string $pdfUrl = null): ?WhatsappMessage
    {
        $contract->loadMissing(['client', 'booking']);

        $body = implode("\n", array_filter([
            'مرحبًا '.($contract->client?->name ?? '').'،',
            $pdfUrl
                ? 'مرفق عقد الحجز رقم '.($contract->booking?->reference ?? '—').'.'
                : 'صدر عقد الحجز رقم '.($contract->booking?->reference ?? '—').'.',
            'رقم العقد: '.$contract->number,
            'نرجو الاطلاع والتأكيد.',
        ]));

        return $this->send($contract->client?->mobile, $body, 'contract', $contract, $userId, $pdfUrl);
    }

    /**
     * إشعار سداد دفعة.
     */
    public function paymentReceived(Booking $booking, float $amount, ?int $userId = null): ?WhatsappMessage
    {
        $booking->loadMissing('client');

        $body = implode("\n", array_filter([
            'تم استلام مبلغ '.number_format($amount, 2).' على الحجز '.$booking->reference.'.',
            $booking->remainingAmount() > 0
                ? 'المتبقي: '.number_format($booking->remainingAmount(), 2)
                : 'اكتمل السداد. شكرًا لكم.',
        ]));

        return $this->send($booking->client?->mobile, $body, 'payment', $booking, $userId);
    }

    /**
     * تذكير بالمبلغ المتبقي (§14).
     *
     * غير تذكير الموعد: هذا يُرسل لأجل المال لا لأجل التاريخ، وقد يُرسل
     * والموعد بعيد. ومن سدّد لا يُطالَب، فبلا متبقٍّ لا رسالة أصلًا.
     */
    public function balanceReminder(Booking $booking, ?int $userId = null): ?WhatsappMessage
    {
        $booking->loadMissing(['unit', 'client']);

        $remaining = $booking->remainingAmount();

        if ($remaining <= 0) {
            return null;
        }

        $body = implode("\n", array_filter([
            'مرحبًا '.($booking->client?->name ?? '').'،',
            'تذكير بالمبلغ المتبقي على حجزكم رقم '.$booking->reference.'.',
            'الوحدة: '.($booking->unit?->name ?? '—'),
            'الموعد: '.$booking->booking_date?->toDateString(),
            'إجمالي الحجز: '.number_format((float) $booking->total_amount, 2),
            'المسدَّد: '.number_format((float) $booking->paid_amount, 2),
            'المتبقي: '.number_format($remaining, 2),
            'نرجو السداد قبل الموعد. شكرًا لكم.',
        ]));

        return $this->send($booking->client?->mobile, $body, 'balance_reminder', $booking, $userId);
    }

    /**
     * إرسال الفاتورة (§14) — مرفقةً بملفها حين يُمرَّر رابطه.
     */
    public function invoice(Booking $booking, ?int $userId = null, ?string $pdfUrl = null): ?WhatsappMessage
    {
        $booking->loadMissing(['unit', 'client']);

        $body = implode("\n", array_filter([
            'مرحبًا '.($booking->client?->name ?? '').'،',
            $pdfUrl
                ? 'مرفق فاتورة حجزكم رقم '.$booking->reference.'.'
                : 'فاتورة حجزكم رقم '.$booking->reference.':',
            'الوحدة: '.($booking->unit?->name ?? '—'),
            'التاريخ: '.$booking->booking_date?->toDateString(),
            'الإجمالي: '.number_format((float) $booking->total_amount, 2),
            'المسدَّد: '.number_format((float) $booking->paid_amount, 2),
            $booking->remainingAmount() > 0
                ? 'المتبقي: '.number_format($booking->remainingAmount(), 2)
                : 'مسدَّدة بالكامل.',
            'شكرًا لتعاملكم معنا.',
        ]));

        return $this->send($booking->client?->mobile, $body, 'invoice', $booking, $userId, $pdfUrl);
    }

    /**
     * إشعار إلغاء (§14).
     *
     * الرسالة تذكر ما دُفع إن كان قد دُفع: العميل الذي ألغي حجزه يسأل عن
     * عربونه قبل أن يسأل عن أي شيء آخر، وصمت الرسالة عنه يجعله يتصل.
     */
    public function bookingCancelled(Booking $booking, ?int $userId = null, ?string $reason = null): ?WhatsappMessage
    {
        $booking->loadMissing(['unit', 'client']);

        $paid = (float) $booking->paid_amount;

        $body = implode("\n", array_filter([
            'مرحبًا '.($booking->client?->name ?? '').'،',
            'نفيدكم بإلغاء الحجز رقم '.$booking->reference.'.',
            'الوحدة: '.($booking->unit?->name ?? '—'),
            'الموعد: '.$booking->booking_date?->toDateString(),
            $reason ? 'السبب: '.$reason : null,
            $paid > 0
                ? 'المبلغ المسدَّد: '.number_format($paid, 2).' — سيتم التواصل معكم بشأنه.'
                : null,
            'نأسف لذلك، ونسعد بخدمتكم في مناسبة قادمة.',
        ]));

        return $this->send($booking->client?->mobile, $body, 'cancellation', $booking, $userId);
    }

    /**
     * توحيد صيغة الرقم السعودي إلى 9665XXXXXXXX.
     */
    private function normalize(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '05')) {
            return '966'.substr($digits, 1);
        }

        if (str_starts_with($digits, '5') && strlen($digits) === 9) {
            return '966'.$digits;
        }

        return $digits ?: null;
    }
}
