<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Whatsapp\CwtsCredentialLookup;
use App\Services\Whatsapp\EnvFile;
use App\Services\Whatsapp\PhoneNumber;
use App\Services\Whatsapp\WhatsappManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * شاشة بوابة الواتساب — المعرّفات في .env، ورقم العمل في الإعدادات. ونصوص
 * الرسائل ليست من شأنها: موضعها مكتبة الإشعارات.
 *
 * على بوابة الشركة (c-wts) يبدأ الربط من الرقم لا من المعرّفات: المنصّة تعرف
 * أي عميل يملك الرقم، فتُجلب معرّفاته وتُكتب في .env دون أن ينسخها أحد بيده.
 * والحقول اليدوية تبقى لبوابة لا تربط بالرقم، أو حين يُعطَّل الجلب.
 */
class WhatsappSettingsController extends Controller
{
    /** أسماء البوابات كما تُعرض في المنتقي؛ مفاتيحها من config/whatsapp.php. */
    private const DRIVER_LABELS = [
        'cwts' => 'C-WTS - c-wts.com',
        'waclient' => 'WaClient - waclient.com',
    ];

    /** أسباب إخفاق الجلب كما تسمّيها المنصّة، مقولةً لمن يقرأ الشاشة. */
    private const LOOKUP_ERRORS = [
        'client_not_found' => 'لا يوجد عميل مسجَّل في المنصّة بهذا الرقم — تأكّد منه أو راجع الدعم.',
        'invalid_lookup_key' => 'مفتاح الجلب غير صحيح — راجع CWTS_LOOKUP_KEY في ملف .env.',
        'incomplete_client' => 'المنصّة لم تسلّم معرّفات كاملة لهذا الرقم — راجع الدعم.',
        'subscription_expired' => 'انتهى اشتراك خدمة الواتساب لهذا الرقم.',
        'lookup_disabled' => 'جلب المعرّفات من المنصّة غير مُفعّل — أدخِل معرّف الجهاز ورمز الوصول يدوياً.',
    ];

    public function __construct(private readonly WhatsappManager $whatsapp) {}

    public function edit(): Response
    {
        $settings = Setting::current();
        $driver = $this->whatsapp->getDefaultDriver();

        return Inertia::render('admin/settings/Whatsapp', [
            'settings' => [
                'wa_enabled' => $this->whatsapp->enabled(),
                'wa_number' => $settings->wa_number,
                'wa_connected_at' => $settings->wa_connected_at?->toDateTimeString(),
            ],
            'gateway' => [
                'driver' => $driver,
                // كل بوابة باسمها المعروض، وهل تربط بالرقم — فالشاشة تتبدّل
                // بتبدّل المنتقى قبل الحفظ لا بعده.
                'drivers' => $this->drivers(),
                'country_code' => (string) config('whatsapp.country_code', '966'),
                // الرابط والمعرّف لكل بوابة، وبصمة التوكن لا التوكن نفسه.
                'credentials' => $this->credentials(),
                'lookup_enabled' => CwtsCredentialLookup::make()->enabled(),
                // .env غير قابل للكتابة يعني شاشةً تعرض ولا تحفظ، فيُقال ذلك قبل المحاولة.
                'env_writable' => EnvFile::make()->writable(),
                'env_path' => EnvFile::make()->path(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'wa_enabled' => ['boolean'],
            'wa_driver' => ['nullable', Rule::in($this->whatsapp->availableDrivers())],
            'wa_country_code' => ['nullable', 'regex:/^\d{1,4}$/'],
            // معرّفات كل بوابة على حدة، فتبقى الأخرى مضبوطة حين تُبدَّل.
            'credentials' => ['array'],
            'credentials.*.base_url' => ['nullable', 'url'],
            'credentials.*.instance_id' => ['nullable', 'string', 'max:191'],
            // فارغ = إبقاء التوكن الحالي دون تغيير.
            'credentials.*.access_token' => ['nullable', 'string', 'max:191'],
            'wa_number' => ['nullable', 'string', 'max:30'],
        ], [
            'credentials.*.base_url.url' => 'رابط الـ API غير صالح.',
            'wa_country_code.regex' => 'مفتاح الدولة يجب أن يكون أرقاماً فقط.',
        ]);

        $env = EnvFile::make();

        if (! $env->writable()) {
            return back()->with('warning', 'ملف .env غير قابل للكتابة على الخادم — تعذّر حفظ معرّفات البوابة.');
        }

        $driver = $data['wa_driver'] ?? $this->whatsapp->getDefaultDriver();
        $keys = (array) config('whatsapp.env_keys');

        $values = [
            $keys['enabled'] => ($data['wa_enabled'] ?? false) ? 'true' : 'false',
            $keys['driver'] => $driver,
            $keys['country_code'] => (string) ($data['wa_country_code'] ?? ''),
        ];

        // بوابةٌ لم يرسلها النموذج تُترك وشأنها، فالحفظ لا يمحو ما لم يُعرَض.
        foreach ((array) ($data['credentials'] ?? []) as $name => $entry) {
            foreach ((array) Arr::get($keys, "drivers.{$name}", []) as $option => $envKey) {
                $values[$envKey] = $option === 'access_token'
                    ? $this->tokenFor($name, (array) $entry)
                    : trim((string) (((array) $entry)[$option] ?? ''));
            }
        }

        $settings = Setting::current();
        $number = PhoneNumber::normalizeOrNull($data['wa_number'] ?? null);

        // رقمٌ جديد يعني عميلاً آخر في المنصّة، فالمعرّفات المجلوبة للرقم
        // السابق تُمحى ليُعاد جلبها عند أوّل ربط — ما لم تُدخَل يدوياً الآن.
        if ($this->refetchesCredentials($driver, $settings->wa_number, $number, $data)) {
            $values[$keys['drivers'][$driver]['instance_id']] = '';
            $values[$keys['drivers'][$driver]['access_token']] = '';
        }

        $env->set($values);
        $this->applyToRuntime($values, $keys);

        if ($settings->wa_number !== $number) {
            // أثر ربطٍ لرقمٍ آخر لا يصف هذا الرقم.
            $settings->wa_connected_at = null;
        }

        $settings->wa_number = $number;
        $settings->save();

        return back()->with('success', 'تم حفظ إعدادات الواتساب بنجاح');
    }

    /** التحقق من حالة الاتصال بالجلسة (يُستدعى عبر fetch من الواجهة). */
    public function status(): JsonResponse
    {
        if (! $this->whatsapp->hasCredentials()) {
            return response()->json([
                'ok' => false,
                'configured' => false,
                'message' => 'أدخِل معرّف الجهاز ورمز الوصول ثم احفظ قبل التحقق.',
            ]);
        }

        // استعلامٌ محض: التثبيت يجري في connect وحده لأنه خلف صلاحية التعديل.
        $result = $this->whatsapp->driver()->status();

        return response()->json([
            'ok' => $result->ok(),
            'configured' => true,
            // «تستجيب البوابة» شيء و«الحساب مرتبط» شيء آخر، فيُقالان معاً.
            'connected' => (bool) $result->json('connected'),
            'status' => $result->json('status'),
            'phone' => $result->json('phone'),
            'avatar_url' => $result->json('avatar_url'),
            'platform' => $result->json('platform'),
            'subscription' => $result->json('subscription'),
            'message' => $result->error(),
            'raw' => $result->toArray(),
        ]);
    }

    /**
     * نبضة الربط: استعلام واحد تستدعيه الواجهة كل ثوانٍ حتى يكتمل الربط.
     * الواجهة لا ترى التوكن — تقرأ حالةً واحدة مترجمة عن البوابة.
     */
    public function connect(Request $request): JsonResponse
    {
        $number = Setting::current()->wa_number;
        $driver = $this->whatsapp->driver();
        $fetched = null;

        // البوابة التي تربط بالرقم لا شيء تسألها عنه قبل أن يُعرف الرقم.
        if ($driver->linksByPhone()) {
            if (blank($number)) {
                return response()->json([
                    'state' => 'missing_number',
                    'message' => 'أدخِل رقم الواتساب المراد ربطه ثم احفظ الإعدادات لبدء الربط.',
                ]);
            }

            if (! $driver->configured()) {
                $fetched = $this->fetchCredentials($number);

                if ($fetched['ok']) {
                    // المُدرِك المحفوظ ما زال يحمل المعرّفات الفارغة.
                    $this->whatsapp->forgetDrivers();
                    $driver = $this->whatsapp->driver();
                } else {
                    return response()->json(['state' => 'missing', 'message' => $fetched['message']]);
                }
            }
        }

        if (! $driver->configured()) {
            return response()->json([
                'state' => 'missing',
                'message' => 'أدخِل معرّف الجهاز ورمز الوصول ثم احفظ الإعدادات لبدء الربط.',
            ]);
        }

        // فحص الرقم مرّةً في أوّل نبضة فقط: لكل فحصٍ حصّةٌ عند البوابة،
        // والنبضة تتكرّر كل ثوانٍ حتى يكتمل المسح.
        if ($driver->linksByPhone() && $request->boolean('check')) {
            $check = $driver->checkNumber($number);

            if ($check->missing()) {
                return response()->json([
                    'state' => 'not_registered',
                    'message' => 'الرقم المُدخل غير مسجَّل في واتساب — صحّح الرقم ثم أعد المحاولة.',
                    'phone' => $check->phone(),
                ]);
            }
        }

        $qr = $driver->qrCode();

        if ($qr->connected()) {
            return $this->connectedResponse($qr->phone(), $qr->account(), $fetched);
        }

        if ($qr->image() !== null) {
            return response()->json([
                'state' => 'qr',
                'qr' => $qr->image(),
                'fetched_for' => $fetched['name'] ?? null,
                'notice' => $fetched['notice'] ?? null,
                'message' => 'امسح الرمز من واتساب › الأجهزة المرتبطة › ربط جهاز.',
            ]);
        }

        return response()->json(match ($qr->code()) {
            'missing_credentials' => ['state' => 'unauthorized', 'message' => 'معرّف الجهاز أو رمز الوصول غير صحيح.'],
            'qr_not_ready' => ['state' => 'pending', 'message' => 'جارٍ توليد الرمز… ثوانٍ قليلة.', 'retrying' => true],
            default => ['state' => 'error', 'message' => $qr->error() ?? 'تعذّر الاتصال بالبوابة — أعد المحاولة بعد قليل.'],
        });
    }

    /**
     * هل تُمحى المعرّفات ليُعاد جلبها بالرقم الجديد؟ لا يكون ذلك إلا على بوابة
     * تربط بالرقم، والجلب مُفعّل، ولم يُدخِل الحافظ معرّفات بيده في النموذج.
     */
    private function refetchesCredentials(string $driver, ?string $was, ?string $now, array $data): bool
    {
        $entry = (array) Arr::get($data, "credentials.{$driver}", []);

        return $driver === 'cwts'
            && filled($now)
            && $was !== $now
            && blank($entry['access_token'] ?? null)
            // معرّفٌ كُتب بيده يُحترم؛ وما جاء كما أرسلناه للشاشة ليس إدخالاً.
            && trim((string) ($entry['instance_id'] ?? '')) === (string) config("whatsapp.drivers.{$driver}.instance_id")
            && CwtsCredentialLookup::make()->enabled();
    }

    /**
     * اسأل المنصّة عن العميل صاحب هذا الرقم واحفظ معرّفاته، فبلا معرّفات
     * لا شيء يُسأل عنه أصلاً. لا يجري إلا حين لا تكون المعرّفات موجودة.
     *
     * @return array{ok: bool, message?: string, name?: string, notice?: string}
     */
    private function fetchCredentials(string $phone): array
    {
        $lookup = CwtsCredentialLookup::make();
        $client = $lookup->enabled() ? $lookup->find($phone) : null;

        if ($client === null) {
            $error = $lookup->enabled() ? $lookup->error() : 'lookup_disabled';

            return [
                'ok' => false,
                'message' => self::LOOKUP_ERRORS[$error ?? '']
                    ?? 'تعذّر جلب معرّفات البوابة من المنصّة'.($error ? " ({$error})" : '').' — أدخِلها يدوياً.',
            ];
        }

        $keys = (array) config('whatsapp.env_keys.drivers.cwts');
        $notice = null;

        try {
            EnvFile::make()->set([
                $keys['instance_id'] => $client['instance_id'],
                $keys['access_token'] => $client['access_token'],
            ]);
        } catch (\Throwable) {
            // ليس قاتلاً: المعرّفات سارية في هذا الطلب، وتُجلب من جديد لاحقاً.
            $notice = 'تم جلب بيانات الاتصال لكن تعذّر حفظها في ملف .env — ستُجلب مرّة أخرى في الزيارة القادمة.';
        }

        config([
            'whatsapp.drivers.cwts.instance_id' => $client['instance_id'],
            'whatsapp.drivers.cwts.access_token' => $client['access_token'],
        ]);

        $this->refreshConfig();

        return [
            'ok' => true,
            'name' => $client['name'] !== '' ? $client['name'] : $phone,
            'notice' => $notice,
        ];
    }

    /** إرسال رسالة تجريبية للتأكد من عمل البوابة. */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $this->whatsapp->isConfigured()) {
            return response()->json([
                'ok' => false,
                'message' => 'التكامل غير مفعّل أو المعرّفات ناقصة. احفظ الإعدادات أولاً.',
            ]);
        }

        $result = $this->whatsapp->driver()->sendText(
            $data['number'],
            $data['message'] ?? 'رسالة تجريبية من لوحة التحكم ✅'
        );

        return response()->json([
            'ok' => $result->ok(),
            'message' => $result->ok()
                ? 'تم إرسال الرسالة التجريبية بنجاح.'
                : ($result->error() ?? 'تعذّر الإرسال — تحقّق من الرقم وحالة الاتصال.'),
            'raw' => $result->toArray(),
        ]);
    }

    /** استجابة «تم الربط» — وهي موضع تثبيت أثر الربط. */
    private function connectedResponse(?string $phone, array $account = [], ?array $fetched = null): JsonResponse
    {
        $settings = Setting::current();
        $expected = $settings->wa_number;

        $settings->wa_connected_at = now();

        if (blank($expected) && filled($phone)) {
            $settings->wa_number = PhoneNumber::normalizeOrNull($phone);
        }

        $settings->save();

        return response()->json([
            'state' => 'connected',
            'phone' => $phone,
            'platform' => $account['platform'] ?? null,
            'subscription' => $account['subscription'] ?? null,
            'fetched_for' => $fetched['name'] ?? null,
            'notice' => $fetched['notice'] ?? null,
            // الرقم المرتبط قد يخالف المُدخل إن مُسح الرمز من جهاز آخر.
            'mismatch' => filled($expected) && filled($phone) && $expected !== PhoneNumber::normalizeOrNull($phone),
            'expected_number' => $expected,
            'message' => 'تم الربط بنجاح، والتكامل جاهز للإرسال.',
        ]);
    }

    /**
     * البوابات التي يمكن انتقاؤها باسمها المعروض.
     *
     * @return list<array{key: string, label: string, links_by_phone: bool}>
     */
    private function drivers(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::DRIVER_LABELS[$key] ?? $key,
            // بوابةٌ مجهولة ترمي عند التحليل، والشاشة تبقى تُعرض.
            'links_by_phone' => (bool) rescue(fn () => $this->whatsapp->driver($key)->linksByPhone(), false, false),
        ], $this->whatsapp->availableDrivers());
    }

    /**
     * ما تعرضه الشاشة عن كل بوابة. التوكن لا يسافر إلى المتصفّح، وبصمته تكفي
     * ليعرف من يقرأ الشاشة أيُّ توكنٍ محفوظ.
     *
     * @return array<string, array{base_url: string, instance_id: string, saved_token: string}>
     */
    private function credentials(): array
    {
        $credentials = [];

        foreach ($this->whatsapp->availableDrivers() as $key) {
            $credentials[$key] = [
                'base_url' => (string) config("whatsapp.drivers.{$key}.base_url"),
                'instance_id' => (string) config("whatsapp.drivers.{$key}.instance_id"),
                'saved_token' => $this->mask((string) config("whatsapp.drivers.{$key}.access_token")),
            ];
        }

        return $credentials;
    }

    /** أوّل أربعة أحرف وآخر أربعة، لا أكثر: ما يكفي للتعرّف لا للاستعمال. */
    private function mask(string $token): string
    {
        if ($token === '') {
            return '';
        }

        return strlen($token) <= 12
            ? str_repeat('•', strlen($token))
            : substr($token, 0, 4).str_repeat('•', 6).substr($token, -4);
    }

    /** التوكن المكتوب، وإلا المحفوظ — فحقلٌ فارغ إبقاءٌ لا محو. */
    private function tokenFor(string $driver, array $entry): string
    {
        $typed = trim((string) ($entry['access_token'] ?? ''));

        return $typed !== '' ? $typed : (string) config("whatsapp.drivers.{$driver}.access_token");
    }

    /**
     * env() لا يُقرأ إلا عند تحميل ملفات الإعداد، فما كُتب في .env يُنسخ إلى
     * الإعداد الحيّ وتُنسى المُدرِكات المحفوظة، وإلا خدمت هذه الصفحة قيمها القديمة.
     */
    private function applyToRuntime(array $values, array $keys): void
    {
        $config = [
            'whatsapp.enabled' => ($values[$keys['enabled']] ?? 'false') === 'true',
            'whatsapp.driver' => $values[$keys['driver']] ?? $this->whatsapp->getDefaultDriver(),
            'whatsapp.country_code' => $values[$keys['country_code']] ?? '',
        ];

        foreach ((array) ($keys['drivers'] ?? []) as $name => $map) {
            foreach ((array) $map as $option => $envKey) {
                if (array_key_exists($envKey, $values)) {
                    $config["whatsapp.drivers.{$name}.{$option}"] = $values[$envKey];
                }
            }
        }

        config($config);
        $this->whatsapp->forgetDrivers();
        $this->refreshConfig();
    }

    /** بلا هذا يبقى env() يعيد القيمة القديمة المخبوزة في الكاش. */
    private function refreshConfig(): void
    {
        if (app()->configurationIsCached()) {
            rescue(fn () => Artisan::call('config:clear'), null, false);
        }
    }
}
