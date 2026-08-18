<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Setting;
use App\Models\WhatsappMessage;
use App\Services\WhatsappNotifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * تذكير العملاء بمواعيدهم تلقائيًا (§14).
 *
 * كان التذكير يُرسل بزرٍّ يضغطه موظف، فيُنسى في اليوم الذي يُنشغل فيه —
 * وهو اليوم الذي يكثر فيه الحجز. فصار له أمرٌ مجدول يمشي كل صباح.
 *
 * ولا يُرسَل تذكيران لحجز واحد: السجل يُسأل قبل الإرسال، فمن ذُكِّر أمس
 * لتأجيلٍ ثم أُعيد تشغيل الأمر لا يُزعج مرتين.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders {--days= : عدد أيام التذكير المسبق، وإلا فمن الإعدادات}';

    protected $description = 'إرسال تذكيرات الواتساب للحجوزات التي يقترب موعدها';

    public function handle(WhatsappNotifier $whatsapp): int
    {
        $settings = Setting::current();

        // التكامل معطَّل يعني أن الرسائل لن تخرج، وإرسالها إلى الطابور يملأ
        // السجل برسائل لم تصل أحدًا.
        if (! $settings->wa_enabled) {
            $this->warn('تكامل الواتساب معطَّل — لا تذكيرات.');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? config('operations.booking.reminder_days_before', 1));
        $target = now()->addDays($days)->toDateString();

        $bookings = Booking::query()
            ->whereDate('booking_date', $target)
            ->whereIn('status', Booking::BLOCKING_STATUSES)
            ->whereHas('client', fn ($q) => $q->whereNotNull('mobile'))
            ->with(['client', 'unit'])
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($bookings as $booking) {
            if ($this->alreadyReminded($booking)) {
                $skipped++;

                continue;
            }

            try {
                $whatsapp->bookingReminder($booking);
                $sent++;
            } catch (Throwable $e) {
                // حجزٌ يفشل تذكيره لا يوقف بقية الحجوزات.
                $this->error("تعذّر تذكير الحجز {$booking->reference}: {$e->getMessage()}");
            }
        }

        $this->info("حجوزات {$target}: {$bookings->count()} — أُرسل {$sent}، وتُخطّي {$skipped} (ذُكِّر سابقًا).");

        return self::SUCCESS;
    }

    /**
     * هل ذُكِّر هذا الحجز في آخر يومين؟
     */
    private function alreadyReminded(Booking $booking): bool
    {
        return WhatsappMessage::query()
            ->where('purpose', 'reminder')
            ->where('related_type', Booking::class)
            ->where('related_id', $booking->id)
            ->where('created_at', '>=', now()->subDays(2))
            ->exists();
    }
}
