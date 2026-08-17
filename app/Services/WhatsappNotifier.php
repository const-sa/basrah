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

        SendWhatsappMessage::dispatch($number, $body);

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
     * إرسال العقد.
     */
    public function contract(Contract $contract, ?int $userId = null): ?WhatsappMessage
    {
        $contract->loadMissing(['client', 'booking']);

        $body = implode("\n", array_filter([
            'مرحبًا '.($contract->client?->name ?? '').'،',
            'مرفق عقد الحجز رقم '.($contract->booking?->reference ?? '—').'.',
            'رقم العقد: '.$contract->number,
            'نرجو الاطلاع والتأكيد.',
        ]));

        return $this->send($contract->client?->mobile, $body, 'contract', $contract, $userId);
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
