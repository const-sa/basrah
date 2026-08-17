<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Setting;
use App\Services\BookingService;
use App\Services\WhatsappNotifier;
use App\Services\ZatcaQr;
use App\Support\Tafqeet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الإجراءات المشتركة بين حجز القاعة وحجز الشاليه.
 *
 * الإنشاء والتعديل انفصلا إلى شاشتين لأن طريقة الحجز مختلفة: فترةٌ في القاعة
 * وليالٍ في الشاليه. أما ما بعد الحجز — الحالة والدفعات والتذكير والحذف —
 * فواحد في النوعين، ولا معنى لمضاعفته. لذلك يبقى هنا بلا شاشة خاصة به،
 * تستدعيه الشاشتان معًا.
 *
 * @see HallBookingsController حجوزات القاعات
 * @see ChaletBookingsController حجوزات الشاليهات
 */
class BookingsController extends Controller
{
    public function __construct(
        private readonly BookingService $bookings,
        private readonly WhatsappNotifier $whatsapp,
    ) {}

    /**
     * تغيير حالة الحجز ضمن المسار المسموح: مبدئي ← مؤكد ← مكتمل.
     */
    public function changeStatus(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Booking::STATUSES))],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->authorizeUnit($request, $booking->unit_id);

        if ($data['status'] === 'cancelled') {
            $this->bookings->cancel($booking, $data['reason'] ?? null);

            return back()->with('success', 'تم إلغاء الحجز');
        }

        // الإنهاء يمرّ بالخدمة لا بتحديث مباشر: عندها يُعترف بالإيراد
        // ويُقفل العربون غير المكتسب. التحديث المباشر يترك الدفاتر ناقصة.
        if ($data['status'] === 'completed') {
            $this->bookings->complete($booking, $request->user()?->id);

            return back()->with('success', 'تم إنهاء الحجز وإثبات إيراده محاسبيًا');
        }

        $booking->update(['status' => $data['status']]);

        return back()->with('success', 'تم تحديث حالة الحجز');
    }

    /**
     * تسجيل دفعة (عربون أو دفعة أو استرداد) مع قيدها المحاسبي،
     * وإشعار العميل على واتساب.
     */
    public function storePayment(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(BookingPayment::TYPES))],
            'method' => ['required', Rule::in(array_keys(BookingPayment::METHODS))],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'notify' => ['boolean'],
        ]);

        $this->authorizeUnit($request, $booking->unit_id);

        $payment = $this->bookings->recordPayment($booking, $data, $request->user()?->id);

        if ($request->boolean('notify') && $data['type'] !== 'refund') {
            $this->whatsapp->paymentReceived($booking->fresh(), (float) $payment->amount, $request->user()?->id);
        }

        return back()->with('success', 'تم تسجيل الدفعة وترحيل قيدها');
    }

    /**
     * دفعات حجز معيّن — تعرضها الواجهة في لوحة جانبية.
     */
    public function payments(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeUnit($request, $booking->unit_id);

        return response()->json([
            'payments' => $booking->payments()->with('receiver:id,name')->latest('id')->get()
                ->map(fn (BookingPayment $p) => [
                    'id' => $p->id,
                    'type' => $p->type,
                    'type_label' => BookingPayment::TYPES[$p->type] ?? $p->type,
                    'method_label' => BookingPayment::METHODS[$p->method] ?? $p->method,
                    'amount' => (float) $p->amount,
                    'signed_amount' => $p->signedAmount(),
                    'paid_on' => $p->paid_on->toDateString(),
                    'reference' => $p->reference,
                    'notes' => $p->notes,
                    'received_by' => $p->receiver?->name,
                ]),
            'summary' => [
                'total_amount' => (float) $booking->total_amount,
                'deposit_amount' => (float) $booking->deposit_amount,
                'paid_amount' => (float) $booking->paid_amount,
                'remaining_amount' => $booking->remainingAmount(),
                'is_deposit_settled' => $booking->isDepositSettled(),
                'is_fully_paid' => $booking->isFullyPaid(),
            ],
        ]);
    }

    /**
     * تعديل ملاحظات الحجز من السجل مباشرة.
     *
     * الملاحظة تُكتب بعد الحجز غالبًا — طلب طارئ من العميل أو تنبيه لموظف
     * المناوبة — ففتح شاشة التعديل كاملةً لسطر واحد تعطيلٌ بلا داعٍ.
     */
    public function updateNotes(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->authorizeUnit($request, $booking->unit_id);

        $booking->update(['notes' => $data['notes']]);

        return back()->with('success', 'تم تحديث الملاحظات');
    }

    /**
     * سند القبض — إثبات استلام المبلغ من العميل، يُطبع ويُسلَّم له.
     */
    public function bond(Request $request, Booking $booking): Response
    {
        $this->authorizeUnit($request, $booking->unit_id);

        $booking->load(['unit:id,name,code,type,logo_path', 'client:id,name,mobile', 'eventType:id,name', 'creator:id,name']);

        $settings = Setting::current();

        // السند يُحرَّر على المقبوض فعلًا لا على إجمالي الحجز: العميل يستلم
        // إيصالًا بما دفعه، والمتبقي يُذكر تحته بيانًا لا مطالبةً في السند.
        $paid = (float) $booking->paid_amount;
        $lastPayment = $booking->payments()->where('type', '!=', 'refund')->latest('paid_on')->latest('id')->first();

        return Inertia::render('admin/bookings/Bond', [
            'bond' => [
                'booking_id' => $booking->id,
                'reference' => $booking->reference,
                'unit_name' => $booking->unit?->name,
                'unit_code' => $booking->unit?->code,
                'unit_logo_url' => $booking->unit?->logoUrl(),
                'unit_type' => $booking->unit?->type,
                // تاريخ السند تاريخ قبض المبلغ لا تاريخ إنشاء الحجز: الإيصال يشهد
                // على واقعة الاستلام. وبلا دفعة يبقى تاريخ الحجز مرجعًا للورقة.
                'issued_on' => $lastPayment?->paid_on?->toDateString()
                    ?? $booking->created_at?->format('Y-m-d'),
                'client_name' => $booking->client?->name,
                'client_mobile' => $booking->client?->mobile,
                'amount' => $paid,
                'amount_words' => Tafqeet::money($paid),
                'total_amount' => (float) $booking->total_amount,
                'remaining_amount' => $booking->remainingAmount(),
                'method_label' => $lastPayment
                    ? (BookingPayment::METHODS[$lastPayment->method] ?? $lastPayment->method)
                    : 'لا يوجد',
                'payment_type_label' => $lastPayment
                    ? (BookingPayment::TYPES[$lastPayment->type] ?? $lastPayment->type)
                    : null,
                'event_name' => $booking->eventType?->name,
                'booking_date' => $booking->booking_date->toDateString(),
                'schedule_label' => $booking->scheduleLabel(),
                'created_by' => $booking->creator?->name,
                'back_url' => $booking->unit?->type === 'chalet'
                    ? '/admin/bookings/chalets'
                    : '/admin/bookings/halls',
            ],
            'issuer' => [
                'business_name' => $settings->business_name ?: config('app.name'),
                'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
                'phone' => $settings->phone,
                'address' => $settings->address,
                'tax_number' => $settings->tax_enabled ? $settings->tax_number : null,
                'manager_name' => $settings->manager_name,
                'stamp_url' => $settings->stamp_path ? asset($settings->stamp_path) : null,
            ],
        ]);
    }

    /**
     * فاتورة ضريبية مبسّطة للحجز — تُطبع وتُسلَّم للعميل.
     *
     * لا سجل لها في الجداول: الحجز نفسه هو الفاتورة، ومبالغه هي بنودها.
     * توليد فاتورة مبيعات (Sale) للحجز كان يزدوج بإيراده — إيراد الحجز
     * يُعترف به عند إنهائه في BookingService، فتُحتسب المناسبة مرتين.
     */
    public function invoice(Request $request, Booking $booking): Response
    {
        $this->authorizeUnit($request, $booking->unit_id);

        $booking->load([
            'unit:id,name,code,type,logo_path', 'client', 'eventType:id,name',
            'package:id,name', 'sections:id,name', 'addons', 'creator:id,name',
        ]);

        $settings = Setting::current();

        // الرقم الضريبي شرط الفاتورة الضريبية: بلا تسجيل تخرج الورقة
        // فاتورةً عادية بلا ضريبة ولا رمز — لا فاتورةً ضريبية بحقول فارغة.
        $taxable = (bool) $settings->tax_enabled && filled($settings->tax_number);
        $rate = $taxable ? (float) $settings->tax_rate : 0.0;

        // إجمالي الحجز هو ما يدفعه العميل فعلًا — عليه وُقّع العقد وحُرّرت
        // سندات القبض وسُجّلت الدفعات. فالضريبة تُستخرج منه شاملًا لا تُضاف
        // فوقه، وإلا خرجت الفاتورة بمبلغ يخالف العقد الذي بيد العميل.
        $gross = (float) $booking->total_amount;
        $net = $rate > 0 ? round($gross / (1 + $rate / 100), 2) : $gross;
        $tax = round($gross - $net, 2);

        $paid = (float) $booking->paid_amount;

        return Inertia::render('admin/bookings/Invoice', [
            'invoice' => [
                'booking_id' => $booking->id,
                'number' => $booking->reference,
                'issued_on' => $booking->created_at?->toDateString(),
                'issued_at' => $booking->created_at?->format('H:i'),
                'is_taxable' => $taxable,
                'tax_rate' => $rate,
                'net_amount' => $net,
                'tax_amount' => $tax,
                'total_amount' => $gross,
                'paid_amount' => $paid,
                'remaining_amount' => $booking->remainingAmount(),
                'payment_status' => $this->paymentStatus($booking),
                'lines' => $this->invoiceLines($booking),
                'methods' => $this->paymentMethodTotals($booking),
                'unit_name' => $booking->unit?->name,
                'unit_code' => $booking->unit?->code,
                'unit_logo_url' => $booking->unit?->logoUrl(),
                'unit_type' => $booking->unit?->type,
                'client_name' => $booking->client?->name,
                'client_mobile' => $booking->client?->mobile,
                'client_tax_number' => $booking->client?->tax_number,
                'client_address' => $booking->client?->tax_address ?: $booking->client?->city,
                'event_name' => $booking->eventType?->name,
                'sections' => $booking->scope === 'whole'
                    ? 'الوحدة كاملة'
                    : ($booking->sections->pluck('name')->implode('، ') ?: '—'),
                'booking_date' => $booking->booking_date->toDateString(),
                'last_day_date' => $booking->isStay() ? $booking->checkOutDate() : $booking->lastDayDate(),
                'duration_label' => $booking->isStay()
                    ? $booking->nightsCount().' ليلة'
                    : $booking->daysCount().' يوم',
                'schedule_label' => $booking->scheduleLabel(),
                'guests_count' => $booking->guests_count,
                'created_by' => $booking->creator?->name,
                'back_url' => $booking->unit?->type === 'chalet'
                    ? '/admin/bookings/chalets'
                    : '/admin/bookings/halls',
            ],
            'issuer' => [
                'business_name' => $settings->business_name ?: config('app.name'),
                'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
                'phone' => $settings->phone,
                'address' => $settings->address,
                'email' => $settings->email,
                'tax_number' => $taxable ? $settings->tax_number : null,
                'commercial_register' => $settings->commercial_register,
                'stamp_url' => $settings->stamp_path ? asset($settings->stamp_path) : null,
                'qr' => $taxable ? $this->invoiceQr($booking, $settings, $gross, $tax) : null,
            ],
        ]);
    }

    /**
     * بنود الفاتورة من مبالغ الحجز المحفوظة لا من إعادة تسعيره:
     * تغيّر جدول الأسعار بعد الحجز لا يغيّر فاتورةً حُرّرت على سعره يومها.
     *
     * @return list<array<string, mixed>>
     */
    private function invoiceLines(Booking $booking): array
    {
        $lines = [];

        $base = (float) $booking->base_amount;

        if ($base > 0) {
            $lines[] = [
                'name' => $booking->isStay()
                    ? 'إيجار '.($booking->unit?->name ?? 'الوحدة')
                    : 'إيجار '.($booking->unit?->name ?? 'القاعة'),
                'note' => $booking->scope === 'whole'
                    ? $booking->scheduleLabel()
                    : $booking->sections->pluck('name')->implode('، '),
                'quantity' => $booking->isStay() ? $booking->nightsCount() : $booking->daysCount(),
                'amount' => $base,
            ];
        }

        if ((float) $booking->package_amount > 0) {
            $lines[] = [
                'name' => 'باقة '.($booking->package?->name ?? '—'),
                'note' => null,
                'quantity' => 1,
                'amount' => (float) $booking->package_amount,
            ];
        }

        // رسم النوع لا يُملأ في الحجوزات الجديدة — يبقى للقديمة التي حملته.
        if ((float) $booking->event_fee_amount > 0) {
            $lines[] = [
                'name' => 'رسوم مناسبة '.($booking->eventType?->name ?? '—'),
                'note' => null,
                'quantity' => 1,
                'amount' => (float) $booking->event_fee_amount,
            ];
        }

        foreach ($booking->addons as $addon) {
            $lines[] = [
                'name' => $addon->name,
                'note' => 'خدمة إضافية',
                'quantity' => (int) ($addon->pivot->quantity ?? 1),
                'amount' => (float) ($addon->pivot->total ?? 0),
            ];
        }

        return $lines;
    }

    /**
     * المقبوض موزّعًا على طرقه — كما تعرضه فاتورة الحجز أسفل الإجماليات.
     *
     * @return list<array{label: string, amount: float}>
     */
    private function paymentMethodTotals(Booking $booking): array
    {
        return $booking->payments()
            ->where('type', '!=', 'refund')
            ->get()
            ->groupBy('method')
            ->map(fn ($group, $method) => [
                'label' => BookingPayment::METHODS[$method] ?? $method,
                'amount' => round((float) $group->sum('amount'), 2),
            ])
            ->values()
            ->all();
    }

    private function paymentStatus(Booking $booking): string
    {
        return match (true) {
            $booking->isFullyPaid() => 'مسدّدة',
            (float) $booking->paid_amount > 0 => 'مسدّدة جزئيًا',
            default => 'غير مسدّدة',
        };
    }

    /**
     * رمز الزكاة والضريبة للفاتورة المبسّطة.
     */
    private function invoiceQr(Booking $booking, Setting $settings, float $total, float $tax): string
    {
        $zatca = app(ZatcaQr::class);

        return $zatca->dataUri($zatca->payload(
            $settings->business_name ?: config('app.name'),
            (string) $settings->tax_number,
            ($booking->created_at ?? now())->toIso8601String(),
            $total,
            $tax,
        ));
    }

    /**
     * إرسال تذكير بالموعد على واتساب.
     */
    public function remind(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeUnit($request, $booking->unit_id);
        $booking->loadMissing('client');

        if (blank($booking->client?->mobile)) {
            return back()->with('warning', 'لا يوجد رقم جوال للعميل.');
        }

        $this->whatsapp->bookingReminder($booking, $request->user()?->id);

        return back()->with('success', 'تم إرسال التذكير على واتساب');
    }

    public function destroy(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeUnit($request, $booking->unit_id);

        // الحجز المسدَّد جزئيًا لا يُحذف — يُلغى ليبقى أثره المالي في السجل.
        if ((float) $booking->paid_amount > 0) {
            return back()->with('warning', 'لا يمكن حذف حجز عليه دفعات — ألغِه بدل حذفه.');
        }

        $booking->delete();

        return back()->with('success', 'تم حذف الحجز');
    }

    /**
     * منع مشرف الوحدة من العمل على وحدة خارج نطاقه.
     */
    private function authorizeUnit(Request $request, int $unitId): void
    {
        if (! $request->user()?->canAccessUnit($unitId)) {
            abort(403, 'ليس لديك صلاحية العمل على هذه الوحدة.');
        }
    }
}
