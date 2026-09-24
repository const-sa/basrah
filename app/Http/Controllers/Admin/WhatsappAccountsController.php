<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Unit;
use App\Models\WhatsappAccount;
use App\Models\WhatsappMessage;
use App\Services\Whatsapp\CwtsCredentialLookup;
use App\Services\Whatsapp\PhoneNumber;
use App\Services\Whatsapp\WhatsappManager;
use App\Support\ActivityPermission;
use App\Support\ActivitySegment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * أرقام الأقسام: لكل قسمٍ أو قاعةٍ رقمه واشتراكه وسجلّ رسائله.
 *
 * الربط هنا كالربط في شاشة البوابة العامة، غير أن المعرّفات تُحفظ في صفّ
 * الحساب (والتوكن مشفّر) لا في .env، فلكل رقمٍ معرّفاته. ومفتاح الجلب من
 * المنصّة واحدٌ للجميع في .env.
 */
class WhatsappAccountsController extends Controller
{
    private const LOOKUP_ERRORS = [
        'client_not_found' => 'لا يوجد عميل مسجَّل في المنصّة بهذا الرقم — تأكّد منه أو راجع الدعم.',
        'invalid_lookup_key' => 'مفتاح الجلب غير صحيح — راجع CWTS_LOOKUP_KEY في ملف .env.',
        'incomplete_client' => 'المنصّة لم تسلّم معرّفات كاملة لهذا الرقم — راجع الدعم.',
        'subscription_expired' => 'انتهى اشتراك خدمة الواتساب لهذا الرقم.',
        'lookup_disabled' => 'جلب المعرّفات من المنصّة غير مُفعّل — أدخِل معرّف الجهاز ورمز الوصول يدوياً.',
    ];

    public function __construct(private readonly WhatsappManager $whatsapp) {}

    public function index(): Response
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $today = now()->toDateString();

        $accounts = WhatsappAccount::with('unit')->ordered()->get()
            ->map(fn (WhatsappAccount $account) => $this->present($account, $monthStart, $today));

        $general = WhatsappMessage::whereNull('whatsapp_account_id')->whereDate('created_at', '>=', $monthStart);

        return Inertia::render('admin/settings/WhatsappAccounts', [
            'accounts' => $accounts,
            'general' => [
                'configured' => $this->whatsapp->isConfigured(),
                'number' => Setting::current()->wa_number,
                'name' => (string) (Setting::current()->business_name ?? config('app.name')),
                'sent' => (clone $general)->where('status', 'sent')->count(),
                'failed' => (clone $general)->where('status', 'failed')->count(),
            ],
            'sections' => collect(ActivitySegment::activities())
                ->map(fn (string $key) => ['key' => $key, 'label' => ActivitySegment::label($key)])->values(),
            'units' => Unit::orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'type'])
                ->map(fn (Unit $unit) => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'section' => ActivityPermission::fromClientType($unit->type),
                ])->filter(fn (array $unit) => $unit['section'] !== null)->values(),
            'drivers' => collect($this->whatsapp->availableDrivers())
                ->map(fn (string $key) => [
                    'key' => $key,
                    'label' => $key === 'cwts' ? 'C-WTS - c-wts.com' : 'WaClient - waclient.com',
                ])->values(),
            'lookup_enabled' => CwtsCredentialLookup::make()->enabled(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $account = new WhatsappAccount(['sort_order' => (int) WhatsappAccount::max('sort_order') + 1]);

        $this->fill($account, $this->validated($request));
        $account->save();

        return back()->with('success', 'تمت إضافة رقم القسم «'.$account->name.'».');
    }

    public function update(Request $request, WhatsappAccount $account): RedirectResponse
    {
        $this->fill($account, $this->validated($request, $account));
        $account->save();

        return back()->with('success', 'تم حفظ رقم «'.$account->name.'».');
    }

    public function destroy(WhatsappAccount $account): RedirectResponse
    {
        // سجلّ رسائله يبقى، ويُقرأ بعده كأنه من البوابة العامة.
        $account->delete();

        return back()->with('success', 'تم حذف رقم «'.$account->name.'». رسائله تعود إلى البوابة العامة.');
    }

    /** حالة الجلسة والاشتراك، دون إرسال شيء لأحد. */
    public function status(WhatsappAccount $account): JsonResponse
    {
        if (! $account->hasCredentials()) {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'message' => 'الرقم غير مربوط بعد — اضغط «ربط» لجلب المعرّفات وعرض الرمز.',
            ]);
        }

        $result = $this->whatsapp->forAccount($account)->status();

        return response()->json([
            'ok' => $result->ok(),
            'configured' => true,
            'connected' => (bool) $result->json('connected'),
            'phone' => $result->json('phone'),
            'platform' => $result->json('platform'),
            'subscription' => $result->json('subscription'),
            'message' => $result->error(),
        ]);
    }

    /** نبضة الربط لرقم القسم — تستدعيها الواجهة كل ثوانٍ حتى يكتمل. */
    public function connect(Request $request, WhatsappAccount $account): JsonResponse
    {
        $driver = $this->whatsapp->forAccount($account);
        $fetched = null;

        if ($driver->linksByPhone()) {
            if (blank($account->wa_number)) {
                return response()->json([
                    'state' => 'missing_number',
                    'message' => 'أدخِل رقم الواتساب لهذا القسم واحفظه لبدء الربط.',
                ]);
            }

            if (! $driver->configured()) {
                $fetched = $this->fetchCredentials($account);

                if (! $fetched['ok']) {
                    return response()->json(['state' => 'missing', 'message' => $fetched['message']]);
                }

                $driver = $this->whatsapp->forAccount($account);
            }
        }

        if (! $driver->configured()) {
            return response()->json([
                'state' => 'missing',
                'message' => 'أدخِل معرّف الجهاز ورمز الوصول لهذا الرقم ثم احفظ لبدء الربط.',
            ]);
        }

        if ($driver->linksByPhone() && $request->boolean('check')) {
            $check = $driver->checkNumber($account->wa_number);

            if ($check->missing()) {
                return response()->json([
                    'state' => 'not_registered',
                    'message' => 'الرقم المُدخل غير مسجَّل في واتساب — صحّح الرقم ثم أعد المحاولة.',
                ]);
            }
        }

        $qr = $driver->qrCode();

        if ($qr->connected()) {
            $expected = $account->wa_number;
            $account->connected_at = now();

            if (blank($expected) && filled($qr->phone())) {
                $account->wa_number = PhoneNumber::normalizeOrNull($qr->phone());
            }

            $account->save();

            return response()->json([
                'state' => 'connected',
                'phone' => $qr->phone(),
                'platform' => $qr->account()['platform'] ?? null,
                'subscription' => $qr->account()['subscription'] ?? null,
                'fetched_for' => $fetched['name'] ?? null,
                'connected_at' => $account->connected_at->format('Y-m-d H:i'),
                'mismatch' => filled($expected) && filled($qr->phone())
                    && $expected !== PhoneNumber::normalizeOrNull($qr->phone()),
                'message' => 'تم ربط رقم «'.$account->name.'» وهو جاهز للإرسال.',
            ]);
        }

        if ($qr->image() !== null) {
            return response()->json([
                'state' => 'qr',
                'qr' => $qr->image(),
                'fetched_for' => $fetched['name'] ?? null,
                'message' => 'امسح الرمز من جوال «'.$account->name.'»: واتساب › الأجهزة المرتبطة › ربط جهاز.',
            ]);
        }

        return response()->json(match ($qr->code()) {
            'missing_credentials' => ['state' => 'unauthorized', 'message' => 'معرّف الجهاز أو رمز الوصول غير صحيح.'],
            'qr_not_ready' => ['state' => 'pending', 'message' => 'جارٍ توليد الرمز… ثوانٍ قليلة.'],
            default => ['state' => 'error', 'message' => $qr->error() ?? 'تعذّر الاتصال بالبوابة — أعد المحاولة بعد قليل.'],
        });
    }

    /** رسالة تجريبية من رقم القسم نفسه. */
    public function test(Request $request, WhatsappAccount $account): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
        ]);

        if (! $account->hasCredentials()) {
            return response()->json(['ok' => false, 'message' => 'الرقم غير مربوط بعد — اربطه أولاً.']);
        }

        $result = $this->whatsapp->forAccount($account)
            ->sendText($data['number'], 'رسالة تجريبية من «'.$account->name.'» ✅');

        return response()->json([
            'ok' => $result->ok(),
            'message' => $result->ok()
                ? 'تم إرسال الرسالة التجريبية من رقم «'.$account->name.'».'
                : ($result->error() ?? 'تعذّر الإرسال — تحقّق من الرقم وحالة الاتصال.'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?WhatsappAccount $account = null): array
    {
        $validator = validator($request->all(), [
            'name' => ['required', 'string', 'max:150'],
            'section' => ['required', Rule::in(ActivitySegment::activities())],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'driver' => ['required', Rule::in($this->whatsapp->availableDrivers())],
            'base_url' => ['nullable', 'url', 'max:191'],
            'instance_id' => ['nullable', 'string', 'max:191'],
            // فارغ = إبقاء التوكن المحفوظ.
            'access_token' => ['nullable', 'string', 'max:500'],
            'wa_number' => ['nullable', 'string', 'max:30'],
            'is_active' => ['boolean'],
        ], [
            'name.required' => 'اكتب الاسم الذي تُرسَل به رسائل هذا الرقم.',
            'base_url.url' => 'رابط الـ API غير صالح.',
        ]);

        $validator->after(function (Validator $v) use ($request, $account) {
            $unitId = $request->integer('unit_id') ?: null;
            $section = (string) $request->input('section');

            if ($unitId) {
                $unitSection = ActivityPermission::fromClientType(Unit::whereKey($unitId)->value('type'));

                if ($unitSection !== $section) {
                    $v->errors()->add('unit_id', 'الوحدة المختارة ليست من هذا القسم.');
                }
            }

            // وحدةٌ واحدة أو قسمٌ واحد لا يُرسَل له من رقمين.
            $taken = WhatsappAccount::query()
                ->when($account, fn ($q) => $q->whereKeyNot($account->id))
                ->when(
                    $unitId,
                    fn ($q) => $q->where('unit_id', $unitId),
                    fn ($q) => $q->whereNull('unit_id')->where('section', $section),
                )
                ->value('name');

            if ($taken) {
                $v->errors()->add($unitId ? 'unit_id' : 'section', "لهذا الموضع رقمٌ آخر: «{$taken}».");
            }
        });

        return $validator->validate();
    }

    private function fill(WhatsappAccount $account, array $data): void
    {
        $number = PhoneNumber::normalizeOrNull($data['wa_number'] ?? null);
        $instanceId = trim((string) ($data['instance_id'] ?? ''));
        $typedToken = trim((string) ($data['access_token'] ?? ''));

        $account->fill([
            'name' => trim($data['name']),
            'section' => $data['section'],
            'unit_id' => $data['unit_id'] ?? null,
            'driver' => $data['driver'],
            'base_url' => filled($data['base_url'] ?? null) ? trim($data['base_url']) : null,
            'instance_id' => $instanceId !== '' ? $instanceId : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        if ($typedToken !== '') {
            $account->access_token = $typedToken;
        }

        if ($account->exists && $account->wa_number !== $number) {
            // أثر ربطٍ لرقمٍ آخر لا يصف هذا الرقم.
            $account->connected_at = null;

            // والمعرّفات المجلوبة للرقم السابق تخصّ عميلاً آخر في المنصّة،
            // فتُمحى ليُعاد جلبها — ما لم يُدخلها الحافظ بيده الآن.
            if ($account->driver === 'cwts' && $typedToken === '' && ! $account->isDirty('instance_id')
                && CwtsCredentialLookup::make()->enabled()) {
                $account->instance_id = null;
                $account->access_token = null;
            }
        }

        $account->wa_number = $number;
    }

    /**
     * @return array{ok: bool, message?: string, name?: string}
     */
    private function fetchCredentials(WhatsappAccount $account): array
    {
        $lookup = CwtsCredentialLookup::make();
        $client = $lookup->enabled() ? $lookup->find($account->wa_number) : null;

        if ($client === null) {
            $error = $lookup->enabled() ? $lookup->error() : 'lookup_disabled';

            return [
                'ok' => false,
                'message' => self::LOOKUP_ERRORS[$error ?? '']
                    ?? 'تعذّر جلب معرّفات البوابة من المنصّة'.($error ? " ({$error})" : '').' — أدخِلها يدوياً.',
            ];
        }

        $account->instance_id = $client['instance_id'];
        $account->access_token = $client['access_token'];
        $account->save();

        return ['ok' => true, 'name' => $client['name'] !== '' ? $client['name'] : $account->wa_number];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WhatsappAccount $account, string $monthStart, string $today): array
    {
        $month = $account->messages()->whereDate('created_at', '>=', $monthStart);

        return [
            'id' => $account->id,
            'name' => $account->name,
            'section' => $account->section,
            'unit_id' => $account->unit_id,
            'target_label' => $account->targetLabel(),
            'driver' => $account->driver,
            'base_url' => (string) $account->base_url,
            'instance_id' => (string) $account->instance_id,
            'saved_token' => $this->mask($account->access_token),
            'wa_number' => $account->wa_number,
            'connected_at' => $account->connected_at?->format('Y-m-d H:i'),
            'is_active' => $account->is_active,
            'linked' => $account->hasCredentials(),
            'stats' => [
                'sent' => (clone $month)->where('status', 'sent')->count(),
                'failed' => (clone $month)->where('status', 'failed')->count(),
                'queued' => (clone $month)->where('status', 'queued')->count(),
                'conversations' => WhatsappMessage::conversationCount($monthStart, $today, $account->id),
                'total' => $account->messages()->count(),
            ],
            'recent' => $account->messages()->latest('id')->limit(5)->get()
                ->map(fn (WhatsappMessage $m) => [
                    'id' => $m->id,
                    'to_number' => $m->to_number,
                    'purpose_label' => $m->purposeLabel(),
                    'status' => $m->status,
                    'error' => $m->error,
                    'created_at' => $m->created_at->format('Y-m-d H:i'),
                ]),
        ];
    }

    private function mask(string $token): string
    {
        if ($token === '') {
            return '';
        }

        return strlen($token) <= 12
            ? str_repeat('•', strlen($token))
            : substr($token, 0, 4).str_repeat('•', 6).substr($token, -4);
    }
}
