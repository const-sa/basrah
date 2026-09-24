<?php

namespace App\Services;

use App\Jobs\SendWhatsappMessage;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Contract;
use App\Models\NotificationTemplate;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\MessageTemplate;
use App\Services\Whatsapp\WhatsappAccounts;
use App\Services\Whatsapp\PhoneNumber;
use App\Support\NotificationCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

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
        // Meta prices marketing apart from utility, so a campaign is not filed as a notice.
        string $category = 'utility',
    ): ?WhatsappMessage {
        $number = $this->normalize($number);

        if (! $number) {
            return null;
        }

        // رقم القسم الذي يخصّ السجلّ، فتصل رسالة المسبح من رقم المسابح
        // ورسالة القاعة من رقم قاعتها.
        $account = app(WhatsappAccounts::class)->for($related);

        $message = WhatsappMessage::create([
            'to_number' => $number,
            'body' => $body,
            'category' => $category,
            'purpose' => $purpose,
            'status' => 'queued',
            'related_type' => $related ? $related::class : null,
            'related_id' => $related?->getKey(),
            'sent_by' => $userId,
            'whatsapp_account_id' => $account?->id,
        ]);

        // Logged where the message is queued, not only where it is sent: the
        // gateway is called by the queue worker, so with no worker running
        // nothing reaches the log and the silence reads as a lost message.
        Log::channel('whatsapp')->info('WhatsappNotifier ⇢ queued', [
            'id' => $message->id,
            'number' => $number,
            'purpose' => $purpose,
            'media_url' => $mediaUrl,
            'account' => $account?->name,
            'queue' => config('queue.default'),
        ]);

        // Stays queued until the gateway answers — marking it sent here reads as delivered.
        SendWhatsappMessage::dispatch($number, $body, $mediaUrl, $message->id, $account?->id);

        return $message;
    }

    /**
     * تأكيد حجز.
     */
    public function bookingConfirmed(Booking $booking, ?int $userId = null): ?WhatsappMessage
    {
        $booking->loadMissing(['unit', 'client']);

        $body = $this->fromTemplate('booking_confirm', $booking)
            ?? implode("\n", array_filter([
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

        $body = $this->fromTemplate('reminder', $booking)
            ?? implode("\n", array_filter([
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

        $body = $this->fromTemplate('contract', $contract->booking, ['contract_number' => (string) $contract->number])
            ?? implode("\n", array_filter([
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

        $body = $this->fromTemplate('payment', $booking, ['amount' => number_format($amount, 2)])
            ?? implode("\n", array_filter([
                'تم استلام مبلغ '.number_format($amount, 2).' على الحجز '.$booking->reference.'.',
                $booking->remainingAmount() > 0
                    ? 'المتبقي: '.number_format($booking->remainingAmount(), 2)
                    : 'اكتمل السداد. شكرًا لكم.',
            ]));

        return $this->send($booking->client?->mobile, $body, 'payment', $booking, $userId);
    }

    /**
     * One payment's voucher — attached as a PDF when a link is passed.
     *
     * The message never promises an attachment it does not carry: without a
     * link it reads as notice of payment, with one as the voucher itself.
     */
    public function paymentReceipt(
        BookingPayment $payment,
        ?int $userId = null,
        ?string $pdfUrl = null,
    ): ?WhatsappMessage {
        $payment->loadMissing(['booking.unit', 'booking.client', 'paymentMethod']);
        $booking = $payment->booking;

        $typeLabel = BookingPayment::TYPES[$payment->type] ?? $payment->type;

        $body = $this->fromTemplate('receipt', $booking, [
            'amount' => number_format((float) $payment->amount, 2),
            'method' => $payment->methodLabel(),
            'payment_type' => $typeLabel,
        ]) ?? implode("\n", array_filter([
            'مرحبًا '.($booking?->client?->name ?? '').'،',
            ($pdfUrl ? 'مرفق سند قبض ' : 'سند قبض ').$typeLabel.' على الحجز '.($booking?->reference ?? '—').'.',
            'المبلغ: '.number_format((float) $payment->amount, 2),
            'التاريخ: '.$payment->paid_on->toDateString(),
            'الطريقة: '.$payment->methodLabel(),
            $booking && $booking->remainingAmount() > 0
                ? 'المتبقي: '.number_format($booking->remainingAmount(), 2)
                : 'اكتمل السداد.',
            'شكرًا لتعاملكم معنا.',
        ]));

        return $this->send($booking?->client?->mobile, $body, 'receipt', $payment, $userId, $pdfUrl);
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

        $body = $this->fromTemplate('balance_reminder', $booking)
            ?? implode("\n", array_filter([
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

        $body = $this->fromTemplate('invoice', $booking)
            ?? implode("\n", array_filter([
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

        $body = $this->fromTemplate('cancellation', $booking, ['reason' => (string) $reason])
            ?? implode("\n", array_filter([
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
     * نصّ المناسبة من مكتبة القوالب، أو null إن لم يُعرَّف قالبٌ لها.
     *
     * القسم يُشتقّ من نوع الوحدة، فيأخذ حجز الشاليه صيغة الشاليهات وحجز
     * القاعة صيغة القاعات دون أن يختار الموظف شيئًا. وحين تخلو المكتبة
     * من القالب يعود المُنادي إلى نصّه المدمج، فلا تسقط رسالة أصلًا.
     *
     * @param  array<string, string>  $extra
     */
    private function fromTemplate(string $event, ?Booking $booking, array $extra = []): ?string
    {
        $category = NotificationCatalog::categoryForUnitType($booking?->unit?->type);

        $template = NotificationTemplate::resolve($event, $category);

        if (! $template) {
            return null;
        }

        return MessageTemplate::render(
            $template->body,
            array_merge($this->variables($booking), $extra),
        );
    }

    /**
     * المتغيّرات المتاحة للقالب من الحجز والعميل والإعدادات.
     *
     * @return array<string, string>
     */
    private function variables(?Booking $booking): array
    {
        $client = $booking?->client;

        return [
            'name' => (string) ($client?->name ?? ''),
            // اسم القسم المُرسِل إن كان له رقمه، وإلا اسم النشاط العام.
            'business_name' => app(WhatsappAccounts::class)->senderName($booking),
            'mobile' => (string) ($client?->mobile ?? ''),
            'reference' => (string) ($booking?->reference ?? ''),
            'unit' => (string) ($booking?->unit?->name ?? '—'),
            'date' => (string) ($booking?->booking_date?->toDateString() ?? ''),
            'period' => (string) ($booking?->periodLabel() ?? ''),
            'total' => number_format((float) ($booking?->total_amount ?? 0), 2),
            'paid' => number_format((float) ($booking?->paid_amount ?? 0), 2),
            'remaining' => number_format((float) ($booking?->remainingAmount() ?? 0), 2),
        ];
    }

    /** Null for a number too short to dial, so the send is dropped rather than wasted. */
    private function normalize(?string $number): ?string
    {
        return PhoneNumber::normalizeOrNull($number, (string) config('whatsapp.country_code', '966'));
    }
}
