<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsappMessage;
use App\Models\Client;
use App\Models\Setting;
use App\Services\WaGateway;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->parseFilters($request);

        $clients = $this->filteredQuery($filters)
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'mobile' => $c->mobile,
                'email' => $c->email,
                'city' => $c->city,
                'national_id' => $c->national_id,
                'is_taxable' => $c->is_taxable,
                'tax_number' => $c->tax_number,
                'tax_address' => $c->tax_address,
                'is_active' => $c->is_active,
                'is_walk_in' => $c->is_walk_in,
                'created_at' => $c->created_at?->format('Y-m-d'),
            ]);

        $stats = [
            'total' => Client::count(),
            'active' => Client::where('is_active', true)->count(),
            'inactive' => Client::where('is_active', false)->count(),
            'taxable' => Client::where('is_taxable', true)->count(),
            'non_taxable' => Client::where('is_taxable', false)->count(),
        ];

        return Inertia::render('admin/clients/Index', [
            'clients' => $clients,
            'filters' => $filters,
            'stats' => $stats,
            // قائمة المدن المفعّلة لتعبئة قائمة الاختيار في نموذج العميل.
            'cities' => \App\Models\City::where('is_active', true)->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * تصدير العملاء (وفق الفلاتر الحالية) إلى ملف CSV يفتح مباشرة في Excel.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->parseFilters($request);
        $query = $this->filteredQuery($filters)->latest('id');

        $filename = 'clients-'.now()->format('Ymd-His').'.csv';

        $columns = [
            '#', 'الاسم', 'الجوال', 'البريد', 'المدينة', 'رقم الهوية',
            'النوع', 'الرقم الضريبي', 'العنوان الضريبي', 'الحالة', 'تاريخ الإنشاء',
        ];

        // تحييد حقن الصيغ (CSV/Formula Injection): أي خلية تبدأ بـ = + - @ أو
        // محرف تحكّم تُسبَق بفاصلة عليا حتى لا تُنفَّذ كصيغة داخل Excel.
        $safe = function ($value) {
            $value = (string) $value;

            return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
        };

        return response()->streamDownload(function () use ($query, $columns, $safe) {
            $out = fopen('php://output', 'w');
            // BOM لضمان قراءة Excel للأحرف العربية بشكل صحيح.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);

            $i = 0;
            $query->chunk(500, function ($clients) use ($out, &$i, $safe) {
                foreach ($clients as $c) {
                    fputcsv($out, [
                        ++$i,
                        $safe($c->name),
                        $safe($c->mobile),
                        $safe($c->email),
                        $safe($c->city),
                        $safe($c->national_id),
                        $c->is_taxable ? 'ضريبي' : 'عادي',
                        $safe($c->tax_number),
                        $safe($c->tax_address),
                        $c->is_active ? 'مفعّل' : 'موقوف',
                        $c->created_at?->format('Y-m-d'),
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function parseFilters(Request $request): array
    {
        return [
            'name' => trim((string) $request->get('name', '')),
            'mobile' => trim((string) $request->get('mobile', '')),
            'email' => trim((string) $request->get('email', '')),
            'city' => trim((string) $request->get('city', '')),
            'status' => (string) $request->get('status', ''),
            'from' => (string) $request->get('from', ''),
            'to' => (string) $request->get('to', ''),
        ];
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        return Client::query()
            ->when($filters['name'] !== '', fn ($q) => $q->where('name', 'like', "%{$filters['name']}%"))
            ->when($filters['mobile'] !== '', fn ($q) => $q->where('mobile', 'like', "%{$filters['mobile']}%"))
            ->when($filters['email'] !== '', fn ($q) => $q->where('email', 'like', "%{$filters['email']}%"))
            ->when($filters['city'] !== '', fn ($q) => $q->where('city', 'like', "%{$filters['city']}%"))
            ->when($filters['status'] === 'active', fn ($q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($filters['status'] === 'taxable', fn ($q) => $q->where('is_taxable', true))
            ->when($filters['status'] === 'non_taxable', fn ($q) => $q->where('is_taxable', false))
            ->when($filters['from'] !== '', fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when($filters['to'] !== '', fn ($q) => $q->whereDate('created_at', '<=', $filters['to']));
    }

    public function store(Request $request): RedirectResponse
    {
        $client = $this->persist($request, new Client());

        $this->sendWelcome($client);

        return back()->with('success', 'تم إضافة العميل بنجاح');
    }

    /**
     * إضافة سريعة من داخل شاشة الحجز: الاسم والجوال فقط.
     *
     * تُعيد العميل بصيغة JSON — لا إعادة توجيه — حتى يُضاف إلى قائمة الاختيار
     * ويُحدَّد فورًا دون مغادرة نموذج الحجز وفقدان ما عُبِّئ فيه.
     * وبقية الحقول (المدينة، الهوية، البيانات الضريبية) تُستكمل لاحقًا من شاشة العملاء.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:50'],
        ]);

        $client = (new Client())->fill([
            'name' => $data['name'],
            'mobile' => $data['mobile'] ?? null,
            'is_active' => true,
        ]);

        $client->save();

        $this->sendWelcome($client);

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'mobile' => $client->mobile,
            ],
        ], 201);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->persist($request, $client);

        return back()->with('success', 'تم تحديث بيانات العميل');
    }

    public function toggle(Client $client): RedirectResponse
    {
        // إيقاف العميل النقدي يوقف كل بيع بلا عميل محدد — فلا يُوقَف.
        if ($client->isWalkIn()) {
            return back()->with('warning', 'العميل النقدي الافتراضي لا يُوقَف — عليه تُحمل فواتير البيع بلا عميل.');
        }

        $client->update(['is_active' => ! $client->is_active]);

        return back()->with('success', 'تم تغيير حالة العميل');
    }

    public function destroy(Client $client): RedirectResponse
    {
        if ($client->isWalkIn()) {
            return back()->with('warning', 'العميل النقدي الافتراضي لا يُحذف — عليه تُحمل فواتير البيع بلا عميل.');
        }

        $client->delete();

        return back()->with('success', 'تم حذف العميل');
    }

    private function persist(Request $request, Client $client): Client
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            // رقم الهوية اختياري — لا يُشترط في أي من النوعين.
            'national_id' => ['nullable', 'string', 'max:50'],
            'is_taxable' => ['boolean'],
            'tax_number' => ['nullable', 'required_if:is_taxable,true', 'string', 'max:100'],
            'tax_address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [
            'tax_number.required_if' => 'الرقم الضريبي مطلوب للعميل الضريبي',
        ]);

        $taxable = $data['is_taxable'] ?? false;

        $client->fill([
            'name' => $data['name'],
            'mobile' => $data['mobile'] ?? null,
            'email' => $data['email'] ?? null,
            'city' => $data['city'] ?? null,
            'national_id' => $data['national_id'] ?? null,
            'is_taxable' => $taxable,
            'tax_number' => $taxable ? ($data['tax_number'] ?? null) : null,
            'tax_address' => $taxable ? ($data['tax_address'] ?? null) : null,
            'is_active' => $data['is_active'] ?? true,
        ])->save();

        return $client;
    }

    /**
     * إرسال رسالة الترحيب عبر الواتساب عند إضافة عميل جديد (إن كان التكامل والترحيب مفعّلين وللعميل رقم جوال).
     * لا يجب أن يُفشل إنشاء العميل إن تعذّر الإرسال — نلتقط أي خطأ ونسجّله فقط.
     */
    private function sendWelcome(Client $client): void
    {
        if (blank($client->mobile)) {
            return;
        }

        $settings = Setting::current();

        if (! $settings->wa_enabled || ! $settings->wa_welcome_enabled || blank($settings->wa_welcome_template)) {
            return;
        }

        try {
            $message = WaGateway::renderTemplate($settings->wa_welcome_template, [
                'name' => $client->name,
                'business_name' => $settings->business_name ?? '',
            ]);

            // الإرسال في الطابور حتى لا يُعلّق إنشاء العميل على استجابة البوابة.
            SendWhatsappMessage::dispatch((string) $client->mobile, $message);
        } catch (\Throwable $e) {
            Log::warning('تعذّر جدولة رسالة ترحيب الواتساب', [
                'client_id' => $client->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
