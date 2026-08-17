<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\Unit;
use App\Models\User;
use App\Services\BookingAvailability;
use App\Services\BookingPricing;
use App\Services\BookingService;
use App\Services\WhatsappNotifier;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ما تشترك فيه شاشتا حجوزات القاعات وحجوزات الشاليهات.
 *
 * الشاشتان منفصلتان لأن طريقة الحجز مختلفة — فترةٌ في القاعة وليالٍ في
 * الشاليه — لكن الفلاتر والإحصاءات وحراسة نطاق الوحدة وعرض الصف واحدة.
 * جمعها هنا يبقي الاختلاف ظاهرًا في الوارث بدل أن يضيع بين تكرارَين.
 */
abstract class BaseBookingsController extends Controller
{
    public function __construct(
        protected readonly BookingAvailability $availability,
        protected readonly BookingPricing $pricing,
        protected readonly BookingService $bookings,
        protected readonly WhatsappNotifier $whatsapp,
    ) {}

    /**
     * نوع الوحدات التي تخدمها الشاشة: hall أو chalet.
     */
    abstract protected function unitType(): string;

    /**
     * قصر الاستعلام على حجوزات هذا النوع.
     */
    abstract protected function scopeToType(Builder $query): Builder;

    /**
     * علاقات إضافية تخصّ شاشة بعينها — القاعة تحتاج المناسبة والباقة،
     * والشاليه لا يحمل أيًّا منهما.
     *
     * @return list<string>
     */
    protected function extraRelations(): array
    {
        return [];
    }

    /**
     * فلاتر تخصّ شاشة بعينها فوق الفلاتر المشتركة.
     */
    protected function applyExtraFilters(Builder $query, Request $request): Builder
    {
        return $query;
    }

    /**
     * الاستعلام المفلتر حسب ما اختاره المستخدم.
     */
    protected function filteredQuery(Request $request): Builder
    {
        $query = Booking::query()
            ->visibleTo($request->user())
            // العقد يُحمَّل مع الصف ليعرض السجل زرّ «العقد» أو زرّ توليده،
            // بدل استعلامٍ لكل حجز عند الرسم.
            ->with(['unit:id,name,code', 'client:id,name,mobile', 'sections:id,name',
                'contracts:id,booking_id,number', ...$this->extraRelations()]);

        // الوحدات التي لا تخصّ هذه الشاشة تُستبعد حتى لو حمل الحجز فترتها:
        // حجز شاليه أُنشئ قبل الفصل يجب أن يظهر في شاشة الشاليهات وحدها.
        $this->scopeToType($query)
            ->whereHas('unit', fn ($u) => $u->where('type', $this->unitType()));

        $query
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->when($request->integer('unit_id'), fn ($q, $id) => $q->where('unit_id', $id))
            ->when($request->string('from')->toString(), fn ($q, $d) => $q->whereDate('booking_date', '>=', $d))
            ->when($request->string('to')->toString(), fn ($q, $d) => $q->whereDate('booking_date', '<=', $d))
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%")
                        ->orWhere('mobile', 'like', "%{$term}%")),
            ));

        return $this->applyExtraFilters($query, $request);
    }

    /**
     * @return array<string, int>
     */
    protected function stats(Builder $query): array
    {
        return [
            'total' => (clone $query)->count(),
            'tentative' => (clone $query)->where('status', 'tentative')->count(),
            // طابور المتابعة: حجوزات تحجز التاريخ وعربونها لم يصل بعد
            'pending_deposit' => (clone $query)->where('status', 'pending_deposit')->count(),
            'confirmed' => (clone $query)->where('status', 'confirmed')->count(),
            'unpaid' => (clone $query)->blocking()->whereColumn('paid_amount', '<', 'total_amount')->count(),
        ];
    }

    /**
     * منع مشرف الوحدة من العمل على وحدة خارج نطاقه.
     */
    protected function authorizeUnit(Request $request, int $unitId): void
    {
        if (! $request->user()?->canAccessUnit($unitId)) {
            abort(403, 'ليس لديك صلاحية العمل على هذه الوحدة.');
        }
    }

    /**
     * منع حجز وحدة من النوع الآخر عبر هذه الشاشة — قاعة تُحجز بالفترة
     * وشاليه يُحجز بالليالي، وخلطهما يُنتج حجزًا لا تعرضه أي شاشة.
     */
    protected function authorizeUnitType(int $unitId): void
    {
        if (Unit::whereKey($unitId)->value('type') !== $this->unitType()) {
            abort(422, 'هذه الوحدة لا تُحجز من هذه الشاشة.');
        }
    }

    /**
     * إنشاء حجز ومعه دفعته الأولى إن سُدِّد عند الحجز.
     *
     * الشاشتان تختلفان في كيفية بناء الحجز لا في كيفية قبضه: موظف الاستقبال
     * يقبض العربون لحظة الحجز في القاعة والشاليه سواء. لذلك يمرّر كل وارث
     * دالة إنشائه، ويبقى القبض هنا في موضع واحد.
     *
     * الحجز ودفعته معاملة واحدة: لو رُفضت الدفعة (تجاوزت الإجمالي مثلًا)
     * لا يبقى حجز يتيم أُنشئ ثم فشل قبضه.
     *
     * @param  callable(): Booking  $create
     * @param  string|null  $redirectTo  وجهة العودة — null تعني الرجوع للصفحة نفسها
     */
    protected function createWithPayment(Request $request, callable $create, ?string $redirectTo = null): RedirectResponse
    {
        $back = fn (): RedirectResponse => $redirectTo ? redirect($redirectTo) : back();

        $payment = $request->validate([
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_type' => ['nullable', Rule::in(array_keys(BookingPayment::TYPES))],
            'payment_method_id' => ['nullable', Rule::exists('payment_methods', 'id')
                ->where('is_active', true)],
            'payment_paid_on' => ['nullable', 'date'],
            'payment_notify' => ['boolean'],
        ]);

        $amount = round((float) ($payment['payment_amount'] ?? 0), 2);

        $booking = DB::transaction(function () use ($create, $payment, $amount, $request) {
            $booking = $create();

            if ($amount <= 0) {
                return $booking;
            }

            if ($amount > (float) $booking->total_amount) {
                throw ValidationException::withMessages([
                    'payment_amount' => 'المبلغ المسدَّد يتجاوز إجمالي الحجز.',
                ]);
            }

            $this->bookings->recordPayment($booking, [
                'type' => $payment['payment_type'] ?? 'deposit',
                'payment_method_id' => $payment['payment_method_id'] ?? null,
                'amount' => $amount,
                'paid_on' => $payment['payment_paid_on'] ?? now()->toDateString(),
                'notes' => 'دفعة مسجّلة عند إنشاء الحجز',
            ], $request->user()?->id);

            return $booking->fresh();
        });

        if ($amount <= 0) {
            return $back()->with('success', "تم إنشاء الحجز {$booking->reference} — غير مسدَّد");
        }

        if ($request->boolean('payment_notify')) {
            $this->whatsapp->paymentReceived($booking, $amount, $request->user()?->id);
        }

        $state = $booking->isFullyPaid() ? 'مسدَّد بالكامل' : 'مسدَّد جزئيًا';

        return $back()->with('success', "تم إنشاء الحجز {$booking->reference} — {$state}");
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(Booking $b): array
    {
        return [
            'id' => $b->id,
            'reference' => $b->reference,
            'unit' => ['id' => $b->unit?->id, 'name' => $b->unit?->name, 'code' => $b->unit?->code],
            'client' => $b->client ? ['id' => $b->client->id, 'name' => $b->client->name, 'mobile' => $b->client->mobile] : null,
            'scope' => $b->scope,
            'sections' => $b->sections->pluck('name')->all(),
            'section_ids' => $b->sections->pluck('id')->all(),
            'period' => $b->period,
            'period_label' => $b->periodLabel(),
            'schedule_label' => $b->scheduleLabel(),
            'booking_date' => $b->booking_date->toDateString(),
            'check_out_date' => $b->check_out_date?->toDateString(),
            'nights' => $b->nights,
            'starts_at' => $b->starts_at->toDateTimeString(),
            'ends_at' => $b->ends_at->toDateTimeString(),
            'status' => $b->status,
            'status_label' => $b->statusLabel(),
            'status_color' => Booking::STATUS_COLORS[$b->status] ?? 'slate',
            // الحجز القادم من الموقع لا موظف وراءه، فيُوسَم في السجل ليعرف
            // الموظف أنه يحتاج متابعةً قبل أن يُعامَل كحجز متفق عليه.
            'is_online' => $b->isOnline(),
            'total_amount' => (float) $b->total_amount,
            'deposit_amount' => (float) $b->deposit_amount,
            'paid_amount' => (float) $b->paid_amount,
            'remaining_amount' => $b->remainingAmount(),
            'is_deposit_settled' => $b->isDepositSettled(),
            'guests_count' => $b->guests_count,
            'notes' => $b->notes,
            // العقد الصادر عن الحجز إن وُجد — السجل يفتحه مباشرة، وغيابه
            // يعني أن الزر يُولّده بدل أن يختفي الطريق إليه.
            'contract' => $b->relationLoaded('contracts') && $b->contracts->isNotEmpty()
                ? ['id' => $b->contracts->first()->id, 'number' => $b->contracts->first()->number]
                : null,
            'has_payments' => (float) $b->paid_amount > 0,
        ];
    }

    /**
     * وحدات هذا النوع التي يصل إليها المستخدم.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function unitOptions(?User $user)
    {
        return Unit::visibleTo($user)
            ->where('is_active', true)
            ->where('type', $this->unitType())
            ->with('sections:id,unit_id,name,gender')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Unit $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'code' => $u->code,
                'type' => $u->type,
                'bookable_mode' => $u->bookable_mode,
                'privacy_mode' => $u->privacy_mode,
                'sections' => $u->sections->map(fn ($s) => [
                    'id' => $s->id, 'name' => $s->name, 'gender' => $s->gender,
                ])->values(),
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function clientOptions()
    {
        return Client::orderBy('name')->limit(500)->get(['id', 'name', 'mobile']);
    }

    /**
     * البيانات الثابتة التي تحتاجها كل شاشة حجز.
     *
     * @return array<string, mixed>
     */
    public static function meta(): array
    {
        return [
            'statuses' => collect(Booking::STATUSES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
                'color' => Booking::STATUS_COLORS[$key] ?? 'slate',
            ])->values()->all(),
            'periods' => BookingPeriod::forView(),
            'stay' => StayPeriod::forView(),
            // طرق الدفع تُقرأ من الجدول لا من قائمة مكتوبة في الشاشة: كانت
            // مكرّرة نصًّا في أربعة نماذج، فالطريقة الجديدة تُضاف ولا تظهر.
            'payment_methods' => PaymentMethod::options(),
        ];
    }
}
