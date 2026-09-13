<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Setting;

/**
 * Receipt voucher data — one source for the screen and the sent PDF.
 *
 * Built twice, the printed sheet and the client's copy drift apart on the
 * first edit.
 */
class BondPayload
{
    /**
     * One payment's voucher — that payment's amount, not the booking's total.
     *
     * @return array<string, mixed>
     */
    public static function forPayment(BookingPayment $payment): array
    {
        $payment->loadMissing([
            'booking.unit:id,name,code,type,logo_path',
            'booking.client:id,name,mobile',
            'booking.eventType:id,name',
            'booking.creator:id,name',
            'paymentMethod:id,code,name,deposits_to',
        ]);

        $booking = $payment->booking;
        $issuedOn = $payment->paid_on->toDateString();
        $typeLabel = BookingPayment::TYPES[$payment->type] ?? $payment->type;

        return [
            ...self::base($booking, $typeLabel),
            'payment_id' => $payment->id,
            'receipt_number' => $booking->reference.'-'.$payment->id,
            'issued_on' => $issuedOn,
            'issued_on_hijri' => Hijri::short($issuedOn),
            ...self::money((float) $payment->amount),
            'method_label' => $payment->methodLabel(),
            'method_kind' => $payment->paymentMethod?->deposits_to,
            'payment_reference' => $payment->reference,
            'payment_type_label' => $typeLabel,
        ];
    }

    /**
     * The booking-wide voucher — everything taken so far, and the last payment.
     *
     * @return array<string, mixed>
     */
    public static function forBooking(Booking $booking): array
    {
        $booking->loadMissing([
            'unit:id,name,code,type,logo_path',
            'client:id,name,mobile',
            'eventType:id,name',
            'creator:id,name',
        ]);

        $lastPayment = $booking->payments()
            ->with('paymentMethod:id,code,name,deposits_to')
            ->where('type', '!=', 'refund')
            ->latest('paid_on')->latest('id')->first();

        // A receipt witnesses the taking of money, so it carries that date;
        // with no payment yet, the booking's own date stands in.
        $issuedOn = $lastPayment?->paid_on?->toDateString()
            ?? $booking->created_at?->format('Y-m-d');

        $typeLabel = $lastPayment
            ? (BookingPayment::TYPES[$lastPayment->type] ?? $lastPayment->type)
            : null;

        return [
            ...self::base($booking, $typeLabel),
            'payment_id' => null,
            'receipt_number' => $booking->reference,
            'issued_on' => $issuedOn,
            'issued_on_hijri' => Hijri::short($issuedOn),
            ...self::money((float) $booking->paid_amount),
            'method_label' => $lastPayment?->methodLabel() ?? 'لا يوجد',
            // The cash/transfer boxes are ticked from where the method banks,
            // so a method the user adds takes its place with no edit here.
            'method_kind' => $lastPayment?->paymentMethod?->deposits_to,
            'payment_reference' => $lastPayment?->reference,
            'payment_type_label' => $typeLabel,
        ];
    }

    /**
     * Letterhead and signatures — the same on both vouchers.
     *
     * @return array<string, mixed>
     */
    public static function issuer(?Setting $settings = null): array
    {
        $settings ??= Setting::current();

        return [
            'business_name' => $settings->business_name ?: config('app.name'),
            'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
            'phone' => $settings->phone,
            'whatsapp' => $settings->whatsapp,
            'email' => $settings->email,
            'address' => $settings->address,
            'tax_number' => $settings->tax_enabled ? $settings->tax_number : null,
            'manager_name' => $settings->manager_name,
            'manager_signature_url' => $settings->manager_signature_path
                ? asset($settings->manager_signature_path)
                : null,
            'stamp_url' => $settings->stamp_path ? asset($settings->stamp_path) : null,
        ];
    }

    /**
     * Booking fields shared by both vouchers.
     *
     * @return array<string, mixed>
     */
    private static function base(Booking $booking, ?string $typeLabel): array
    {
        return [
            'booking_id' => $booking->id,
            'reference' => $booking->reference,
            'unit_name' => $booking->unit?->name,
            'unit_code' => $booking->unit?->code,
            'unit_logo_url' => $booking->unit?->logoUrl(),
            'unit_type' => $booking->unit?->type,
            'client_name' => $booking->client?->name,
            'client_mobile' => $booking->client?->mobile,
            'total_amount' => (float) $booking->total_amount,
            'remaining_amount' => $booking->remainingAmount(),
            'event_name' => $booking->eventType?->name,
            'booking_date' => $booking->booking_date->toDateString(),
            'schedule_label' => $booking->scheduleLabel(),
            'paid_for' => self::paidFor($booking, $typeLabel),
            'created_by' => $booking->creator?->name,
            'back_url' => $booking->unit?->type === 'chalet'
                ? '/admin/bookings/chalets'
                : '/admin/bookings/halls',
        ];
    }

    /** What the money was taken for — the pad's «وذلك قيمة» line. */
    private static function paidFor(Booking $booking, ?string $typeLabel): string
    {
        $what = $booking->eventType?->name
            ? 'مناسبة '.$booking->eventType->name
            : trim('حجز '.($booking->unit?->name ?? ''));

        $kind = $typeLabel ? ' ('.$typeLabel.')' : '';

        return $what.$kind.' بتاريخ '.$booking->booking_date->toDateString().' — '.$booking->scheduleLabel();
    }

    /**
     * Riyals and halalas for the two header boxes, and the amount in words.
     *
     * Rounded before splitting, or 0.999 prints ninety-nine halalas.
     *
     * @return array{amount: float, amount_riyals: int, amount_halalas: int, amount_words: string}
     */
    private static function money(float $amount): array
    {
        $amount = round($amount, 2);

        return [
            'amount' => $amount,
            'amount_riyals' => (int) floor($amount),
            'amount_halalas' => (int) round(($amount - floor($amount)) * 100),
            'amount_words' => Tafqeet::money($amount),
        ];
    }
}
