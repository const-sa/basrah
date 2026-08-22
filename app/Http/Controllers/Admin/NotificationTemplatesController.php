<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsappMessage;
use App\Models\Client;
use App\Models\NotificationTemplate;
use App\Models\Setting;
use App\Services\WaGateway;
use App\Support\NotificationCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationTemplatesController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/notifications/Library', [
            'templates' => NotificationTemplate::query()
                ->orderBy('category')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (NotificationTemplate $t) => [
                    'id' => $t->id,
                    'category' => $t->category,
                    'category_label' => $t->categoryLabel(),
                    'event' => $t->event,
                    'event_label' => $t->eventLabel(),
                    'title' => $t->title,
                    'body' => $t->body,
                    'is_active' => $t->is_active,
                    'sort_order' => $t->sort_order,
                    'created_at' => $t->created_at?->toDateString(),
                ]),
            'catalog' => NotificationCatalog::forFrontend(),
            'clients' => Client::where('is_active', true)
                ->whereNotNull('mobile')
                ->orderBy('name')
                ->get(['id', 'name', 'mobile'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'mobile' => $c->mobile]),
            'wa_configured' => (new WaGateway)->isConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        NotificationTemplate::create($this->validated($request));

        return back()->with('success', 'تم إضافة القالب إلى المكتبة');
    }

    public function update(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $template->update($this->validated($request));

        return back()->with('success', 'تم تحديث القالب');
    }

    public function destroy(NotificationTemplate $template): RedirectResponse
    {
        $template->delete();

        return back()->with('success', 'تم حذف القالب من المكتبة');
    }

    /**
     * إرسال قالب الإشعار عبر الواتساب إلى عميل محدّد أو إلى كل العملاء.
     */
    public function send(Request $request, NotificationTemplate $template): RedirectResponse
    {
        $data = $request->validate([
            'target' => ['required', 'in:client,all'],
            'client_id' => ['nullable', 'required_if:target,client', 'exists:clients,id'],
        ]);

        $gateway = new WaGateway;

        if (! $gateway->isConfigured()) {
            return back()->with('warning', 'تكامل الواتساب غير مفعّل. اربط الجهاز من إعدادات الواتساب أولاً.');
        }

        $businessName = Setting::current()->business_name ?? config('app.name');

        $clients = $data['target'] === 'all'
            ? Client::where('is_active', true)->whereNotNull('mobile')->get()
            : Client::whereKey($data['client_id'])->get();

        if ($clients->isEmpty()) {
            return back()->with('warning', 'لا يوجد عملاء لديهم رقم جوال لإرسال الإشعار إليهم.');
        }

        // الإرسال في الطابور: مهمة لكل عميل حتى لا يُعلّق الطلب أو يُنهك العمّال
        // عند الإرسال الجماعي لعدد كبير من العملاء.
        $queued = 0;

        foreach ($clients as $client) {
            $message = WaGateway::renderTemplate($template->body, [
                'name' => $client->name,
                'business_name' => $businessName,
                'mobile' => (string) $client->mobile,
            ]);

            SendWhatsappMessage::dispatch((string) $client->mobile, $message);
            $queued++;
        }

        return back()->with('success', "تمت جدولة إرسال القالب إلى {$queued} عميل.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'category' => ['required', Rule::in(NotificationCatalog::categoryKeys())],
            'event' => ['required', Rule::in(NotificationCatalog::eventKeys())],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
    }
}
