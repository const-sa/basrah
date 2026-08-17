<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إدارة طرق الدفع من شاشة الإعدادات.
 *
 * الطرق كانت ثوابت في الكود، فإضافة واحدة تستدعي مبرمجًا. صارت صفوفًا
 * يملكها المستخدم: يضيف ويعطّل ويرتّب، ويحدّد لكل طريقة حسابها في الدفاتر.
 *
 * والطريقة مشتركة في النظام كله: المفعّلة تظهر في الحجوزات والكاشير والسندات.
 */
class PaymentMethodsController extends Controller
{
    public function index(): Response
    {
        $methods = PaymentMethod::ordered()->get()->map(fn (PaymentMethod $m) => [
            'id' => $m->id,
            'code' => $m->code,
            'name' => $m->name,
            'deposits_to' => $m->deposits_to,
            'deposits_to_label' => $m->destinationLabel(),
            'is_credit' => $m->is_credit,
            'is_active' => $m->is_active,
            'is_system' => $m->is_system,
            'sort_order' => $m->sort_order,
            // عدد المستندات المرتبطة — يشرح للمستخدم لماذا يُمنع الحذف.
            'usage_count' => $m->usageCount(),
        ]);

        return Inertia::render('admin/settings/PaymentMethods', [
            'methods' => $methods,
            'destinations' => collect(PaymentMethod::DESTINATIONS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'stats' => [
                'total' => PaymentMethod::count(),
                'active' => PaymentMethod::where('is_active', true)->count(),
                'inactive' => PaymentMethod::where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        PaymentMethod::create($data);

        return back()->with('success', 'تم إضافة طريقة الدفع');
    }

    public function update(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $data = $this->validated($request, $paymentMethod);

        // الطريقة الأساسية يُعدَّل اسمها وحساب إيداعها وترتيبها، ولا يُمسّ
        // كودها ولا صفة الآجل: النظام يستدعيها بكودها («cash» افتراضًا لكل
        // خدمة) ويبني على الآجل سلوكًا، فتغييرهما يكسر سلوكًا لا شكلًا.
        if ($paymentMethod->is_system) {
            unset($data['code'], $data['is_credit']);
        }

        $paymentMethod->update($data);

        return back()->with('success', 'تم تحديث طريقة الدفع');
    }

    public function toggle(PaymentMethod $paymentMethod): RedirectResponse
    {
        // تعطيل النقد يترك الحجوزات والفواتير بلا افتراض تسقط عليه.
        if ($paymentMethod->is_system && $paymentMethod->is_active) {
            return back()->with('error', 'لا يمكن تعطيل طريقة أساسية يعتمدها النظام');
        }

        $paymentMethod->update(['is_active' => ! $paymentMethod->is_active]);

        return back()->with('success', 'تم تغيير حالة طريقة الدفع');
    }

    public function destroy(PaymentMethod $paymentMethod): RedirectResponse
    {
        if ($paymentMethod->is_system) {
            return back()->with('error', 'لا يمكن حذف طريقة أساسية يعتمدها النظام — عطّلها إن لم تكن تستعملها');
        }

        // المستند القديم يجب أن يبقى شاهدًا على طريقة قبضه، فالمستعملة
        // تُعطَّل ولا تُحذف: الحذف يترك دفعاتٍ بلا طريقة في كل تقرير.
        if ($paymentMethod->isInUse()) {
            return back()->with('error', 'لا يمكن حذف طريقة مستعملة في مستندات — عطّلها لتختفي من قوائم الاختيار');
        }

        $paymentMethod->delete();

        return back()->with('success', 'تم حذف طريقة الدفع');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PaymentMethod $existing = null): array
    {
        $data = $request->validate([
            // الكود معرّف تقني يُستدعى من الخدمات، فيُقيَّد بحروف لاتينية
            // وشرطة سفلية: العربية أو المسافة تكسر استدعاءه في الكود.
            'code' => [
                'required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('payment_methods', 'code')->ignore($existing?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'deposits_to' => ['required', Rule::in(array_keys(PaymentMethod::DESTINATIONS))],
            'is_credit' => ['boolean'],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], [], [
            'code' => 'الكود',
            'name' => 'الاسم',
            'deposits_to' => 'حساب الإيداع',
        ]);

        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
