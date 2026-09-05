<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\AuthorizesByUnitType;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Setting;
use App\Services\BookingService;
use App\Services\WhatsappNotifier;
use App\Services\ZatcaQr;
use App\Support\Hijri;
use App\Support\Tafqeet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    // المسار المشترك لا يعرف نوع الحجز قبل جلبه، فالوسيط يقبل بديلَي النوعين
    // ويتم الفصل هنا بمفتاح النوع وحده.
    use AuthorizesByUnitType;

    /**
     * إيصال الدفعة — قائمة بيضاء صارمة بالامتداد ونوع المحتوى معًا: مرفق يفتحه
     * محاسب لاحقًا لا يصحّ أن يكون صفحة أو ملفًا تنفيذيًا.
     *
     * الشرط واحد أينما أُرفق الإيصال — مع تسجيل الدفعة أو من ورقة الفاتورة —
     * فيُكتب مرةً ويُقرأ في الموضعين.
     *
     * @var list<string>
     */
    private const RECEIPT_RULES = [
        'file',
        'max:5120', // 5MB
        'mimes:pdf,jpg,jpeg,png,webp',
        'mimetypes:application/pdf,image/jpeg,image/png,image/webp',
    ];

    /** المجلد الذي تُحفظ فيه إيصالات الدفعات على قرص public. */
    private const RECEIPT_DIR = 'booking-payments';

    public function __construct(
        private readonly BookingService $bookings,
        private readonly WhatsappNotifier $whatsapp,
    ) {}

    /**
     * تغيير حالة الحجز: مدفوع العربون ← مسدد كامل،
     * ويخرج من مساره في أي نقطة إلى «مؤجل» أو «ملغى».
     */
    public function changeStatus(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeBookingAction($request, $booking, 'edit');

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Booking::STATUSES))],
            'reason' => ['nullable', 'string', 'max:1000'],
            'notify' => ['boolean'],
        ]);

        $this->authorizeUnit($request, $booking->unit_id);

        $reason = $data['reason'] ?? null;

        // الحالات ذات الأثر تمرّ بالخدمة لا بتحديث مباشر: هناك يُعترف
        // بالإيراد ويُقفل العربون ويُسجَّل سبب الإلغاء. التحديث المباشر
        // يترك الدفاتر أو سجل التدقيق ناقصًا.
        match ($data['status']) {
            'cancelled' => $this->bookings->cancel($booking, $reason),
            'postponed' => $this->bookings->postpone($booking, $reason),
            'paid_in_full' => $this->bookings->settleInFull($booking, $request->user()?->id),
            default => $booking->update(['status' => $data['status']]),
        };

        // إشعار الإلغاء (§14) يُرسل بطلبٍ صريح كبقية الإشعارات، لا تلقائيًا:
        // الإلغاء قد يقع تصحيحًا لخطأ إدخال، ورسالةٌ تخرج حينها تُقلق عميلًا
        // لم يُلغَ حجزه أصلًا.
        if ($data['status'] === 'cancelled' && $request->boolean('notify')) {
            $this->whatsapp->bookingCancelled($booking->fresh(), $request->user()?->id, $reason);
        }

        return back()->with('success', match ($data['status']) {
            'cancelled' => 'تم إلغاء الحجز',
            'postponed' => 'تم تأجيل الحجز وتحرير الفترة',
            'paid_in_full' => 'تم إقفال الحجز مسدَّدًا وإثبات الإيراد محاسبيًا',
            default => 'تم تحديث حالة الحجز',
        });
    }

    /**
     * تسجيل دفعة (عربون أو دفعة أو استرداد) مع قيدها المحاسبي،
     * وإشعار العميل على واتساب.
     */
    public function storePayment(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeBookingAction($request, $booking, 'edit');

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(BookingPayment::TYPES))],
            // الطريقة تُفحص في الجدول لا في ثابت: المعطَّلة تُرفض هنا،
            // فلا تُسجَّل دفعة بطريقة لا تُعرض في الشاشة.
            //
            // A forfeit is the exception: no money moves, so demanding a
            // method would have the clerk name a till nothing passes through.
            'payment_method_id' => [
                Rule::requiredIf(fn () => $request->input('type') !== 'security_forfeit'),
                'nullable',
                Rule::exists('payment_methods', 'id')->where('is_active', true),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'attachment' => ['nullable', ...self::RECEIPT_RULES],
            'notes' => ['nullable', 'string', 'max:1000'],
            'notify' => ['boolean'],
        ], $this->receiptMessages('attachment'));

        $this->authorizeUnit($request, $booking->unit_id);

        // الملف يُخزّن بعد التحقق من الصلاحية لا قبله: لا يُترك مرفق يتيم على القرص
        // من محاولةٍ رُدّت.
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store(self::RECEIPT_DIR, 'public');
        }

        $payment = $this->bookings->recordPayment($booking, $data, $request->user()?->id);

        // A security movement changes nothing the guest owes, so the "payment
        // received" message would only make them think their balance moved.
        $silent = ['refund', ...BookingPayment::SECURITY_TYPES];

        if ($request->boolean('notify') && ! in_array($data['type'], $silent, true)) {
            $this->whatsapp->paymentReceived($booking->fresh(), (float) $payment->amount, $request->user()?->id);
        }

        return back()->with('success', 'تم تسجيل الدفعة وترحيل قيدها');
    }

    /**
     * دفعات حجز معيّن — تعرضها الواجهة في لوحة جانبية.
     */
    public function payments(Request $request, Booking $booking): JsonResponse
    {
        $this->authorizeBookingAction($request, $booking, 'view');

        $this->authorizeUnit($request, $booking->unit_id);

        return response()->json([
            'payments' => $booking->payments()->with(['receiver:id,name', 'paymentMethod:id,name'])->latest('id')->get()
                ->map(fn (BookingPayment $p) => [
                    'id' => $p->id,
                    'type' => $p->type,
                    'type_label' => BookingPayment::TYPES[$p->type] ?? $p->type,
                    'method_label' => $p->methodLabel(),
                    'amount' => (float) $p->amount,
                    'signed_amount' => $p->signedAmount(),
                    'paid_on' => $p->paid_on->toDateString(),
                    'reference' => $p->reference,
                    'attachment_url' => $p->attachmentUrl(),
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
                // The security deposit is tracked beside the price, never
                // inside it — agreed, and what is actually still held.
                'security_deposit_amount' => (float) $booking->security_deposit_amount,
                'security_held' => $booking->securityHeld(),
            ],
        ]);
    }

    /**
     * إرفاق إيصال بدفعة قائمة — أو استبدال إيصالها — من ورقة الفاتورة.
     *
     * الإرفاق عند تسجيل الدفعة ليس دائمًا ممكنًا: الحوالة تصل بعد قيد الدفعة
     * بيوم، ومن يفتح الفاتورة ليراجعها هو من يجد الإيصال بيده. فبابٌ ثانٍ
     * للإرفاق على الدفعة نفسها لا نسخة ثانية منها.
     */
    public function storeReceipt(Request $request, Booking $booking, BookingPayment $payment): RedirectResponse
    {
        $this->authorizeBookingAction($request, $booking, 'edit');

        $this->authorizeUnit($request, $booking->unit_id);

        // الدفعة تُطابَق بحجزها: رقمٌ في الرابط لا يكفي لفتح دفعة حجزٍ آخر.
        abort_unless($payment->booking_id === $booking->id, 404);

        $request->validate([
            'receipt' => ['required', ...self::RECEIPT_RULES],
        ], $this->receiptMessages('receipt'));

        $previous = $payment->attachment_path;

        $payment->update([
            'attachment_path' => $request->file('receipt')->store(self::RECEIPT_DIR, 'public'),
        ]);

        // الإيصال القديم يُحذف بعد نجاح حفظ الجديد لا قبله — لئلا يضيع الاثنان.
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return back()->with('success', 'تم إرفاق إيصال الدفعة');
    }

    /**
     * حذف إيصال دفعة — يُرفع خطأً فيُزال ويُرفق الصحيح.
     */
    public function destroyReceipt(Request $request, Booking $booking, BookingPayment $payment): RedirectResponse
    {
        $this->authorizeBookingAction($request, $booking, 'edit');

        $this->authorizeUnit($request, $booking->unit_id);

        abort_unless($payment->booking_id === $booking->id, 404);

        if ($payment->attachment_path) {
            Storage::disk('public')->delete($payment->attachment_path);
            $payment->update(['attachment_path' => null]);
        }

        return back()->with('success', 'تم حذف المرفق');
    }

    /**
     * رسائل رفض المرفق بالعربية — الحقل يختلف باختلاف الباب، والرسالة واحدة.
     *
     * @return array<string, string>
     */
    private function receiptMessages(string $field): array
    {
        return [
            "{$field}.mimes" => 'نوع الملف غير مسموح. المسموح: PDF أو صورة.',
            "{$field}.mimetypes" => 'نوع الملف غير مسموح. المسموح: PDF أو صورة.',
            "{$field}.max" => 'حجم المرفق يتجاوز 5 ميجابايت.',
            "{$field}.required" => 'اختر ملف الإيصال أولًا.',
        ];
    }

    /**
     * تعديل ملاحظات الحجز من السجل مباشرة.
     *
     * الملاحظة تُكتب بعد الحجز غالبًا — طلب طارئ من العميل أو تنبيه لموظف
     * المناوبة — ففتح شاشة التعديل كاملةً لسطر واحد تعطيلٌ بلا داعٍ.
     */
    public function updateNotes(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeBookingAction($request, $booking, 'edit');

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
        $this->authorizeBookingAction($request, $booking, 'view');

        $this->authorizeUnit($request, $booking->unit_id);

        $booking->load(['unit:id,name,code,type,logo_path', 'client:id,name,mobile', 'eventType:id,name', 'creator:id,name']);

        $settings = Setting::current();

        // السند يُحرَّر على المقبوض فعلًا لا على إجمالي الحجز: العميل يستلم
        // إيصالًا بما دفعه، والمتبقي يُذكر تحته بيانًا لا مطالبةً في السند.
        $paid = round((float) $booking->paid_amount, 2);
        $lastPayment = $booking->payments()
            ->with('paymentMethod:id,code,name,deposits_to')
            ->where('type', '!=', 'refund')
            ->latest('paid_on')->latest('id')->first();

        $issuedOn = $lastPayment?->paid_on?->toDateString()
            ?? $booking->created_at?->format('Y-m-d');

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
                'issued_on' => $issuedOn,
                'issued_on_hijri' => Hijri::short($issuedOn),
                'client_name' => $booking->client?->name,
                'client_mobile' => $booking->client?->mobile,
                'amount' => $paid,
                // الخانتان في رأس السند ريال وهللة منفصلتين كما في الدفتر
                // المطبوع — والتقريب قبل الفصل وإلا خرجت 0.999 هللة تسعةً وتسعين.
                'amount_riyals' => (int) floor($paid),
                'amount_halalas' => (int) round(($paid - floor($paid)) * 100),
                'amount_words' => Tafqeet::money($paid),
                'total_amount' => (float) $booking->total_amount,
                'remaining_amount' => $booking->remainingAmount(),
                'method_label' => $lastPayment?->methodLabel() ?? 'لا يوجد',
                // مربّعا «نقدًا» و«شيك/حوالة» يُعلّمان من مقصد الطريقة لا من
                // اسمها: طريقة يُضيفها المستخدم تأخذ مكانها بلا تعديل هنا.
                'method_kind' => $lastPayment?->paymentMethod?->deposits_to,
                'payment_reference' => $lastPayment?->reference,
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
                'whatsapp' => $settings->whatsapp,
                'email' => $settings->email,
                'address' => $settings->address,
                'tax_number' => $settings->tax_enabled ? $settings->tax_number : null,
                'manager_name' => $settings->manager_name,
                'manager_signature_url' => $settings->manager_signature_path
                    ? asset($settings->manager_signature_path)
                    : null,
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
        $this->authorizeBookingAction($request, $booking, 'view');

        $this->authorizeUnit($request, $booking->unit_id);

        $booking->load([
            'unit:id,name,code,type,logo_path', 'client', 'eventType:id,name',
            'package:id,name', 'sections:id,name', 'addons', 'creator:id,name',
        ]);

        $settings = Setting::current();

        // الرقم الضريبي شرط الفاتورة الضريبية: بلا تسجيل تخرج الورقة
        // فاتورةً عادية بلا ضريبة ولا رمز — لا فاتورةً ضريبية بحقول فارغة.
        //
        // والضريبة أُضيفت فوق المُسعَّر يوم الحجز وخُزِّنت داخل الإجمالي، فتُقرأ
        // من الحجز نفسه لا تُحتسب من جديد: حجزٌ سُجِّل والضريبة معطّلة يخرج
        // بفاتورةٍ بلا ضريبة وإن فُعِّلت اليوم، وهو ما وقّع عليه العميل.
        $gross = (float) $booking->total_amount;
        $net = $booking->netAmount();
        $tax = $booking->taxAmount();
        $taxable = $tax > 0 && filled($settings->tax_number);
        $rate = $taxable && $net > 0 ? round($tax / $net * 100, 2) : 0.0;

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
                'payments' => $this->invoicePayments($booking),
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
     * دفعات الحجز وإيصالاتها — تُعرض في ورقة الفاتورة لتُرفق منها.
     *
     * تُسرد الدفعات كلها لا المُرفَق منها وحده: الصفّ الخالي من إيصالٍ هو
     * موضع الزرّ الذي يُرفق به، وإخفاؤه يُخفي الباب نفسه.
     *
     * ولا تُطبع: رابطٌ على ورقةٍ تُسلّم للعميل لا يُفتح.
     *
     * @return list<array<string, mixed>>
     */
    private function invoicePayments(Booking $booking): array
    {
        return $booking->payments()
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get()
            ->map(fn (BookingPayment $p) => [
                'id' => $p->id,
                'type_label' => BookingPayment::TYPES[$p->type] ?? $p->type,
                'amount' => (float) $p->amount,
                'paid_on' => $p->paid_on->toDateString(),
                'reference' => $p->reference,
                'method_label' => $p->methodLabel(),
                'url' => $p->attachmentUrl(),
            ])
            ->all();
    }

    /**
     * المقبوض موزّعًا على طرقه — كما تعرضه فاتورة الحجز أسفل الإجماليات.
     *
     * @return list<array{label: string, amount: float}>
     */
    private function paymentMethodTotals(Booking $booking): array
    {
        return $booking->payments()
            ->with('paymentMethod:id,name')
            ->where('type', '!=', 'refund')
            ->get()
            ->groupBy('payment_method_id')
            ->map(fn ($group) => [
                'label' => $group->first()->methodLabel(),
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

    /**
     * تذكير بالمبلغ المتبقي (§14) — غير تذكير الموعد.
     */
    public function remindBalance(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeUnit($request, $booking->unit_id);
        $booking->loadMissing('client');

        if (blank($booking->client?->mobile)) {
            return back()->with('warning', 'لا يوجد رقم جوال للعميل.');
        }

        // من سدَّد لا يُطالَب: الرسالة لا تُرسل، والرد يقول لماذا.
        if ($booking->remainingAmount() <= 0) {
            return back()->with('warning', 'لا مبلغ متبقٍّ على هذا الحجز.');
        }

        $this->whatsapp->balanceReminder($booking, $request->user()?->id);

        return back()->with('success', 'تم إرسال التذكير بالمبلغ المتبقي');
    }

    /**
     * إرسال الفاتورة على واتساب (§14).
     */
    public function sendInvoice(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeUnit($request, $booking->unit_id);
        $booking->loadMissing('client');

        if (blank($booking->client?->mobile)) {
            return back()->with('warning', 'لا يوجد رقم جوال للعميل.');
        }

        $this->whatsapp->invoice($booking, $request->user()?->id);

        return back()->with('success', 'تم إرسال الفاتورة على واتساب');
    }

    public function destroy(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorizeBookingAction($request, $booking, 'delete');

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
