<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * إعدادات الضريبة — التفعيل والرقم الضريبي والنسبة.
 *
 * كانت بطاقةً داخل الإعدادات العامة بين الشعار والتواقيع، وليست من بابها:
 * النسبة تدخل في حساب كل فاتورة وعرض سعر وحجز، والرقم الضريبي يُطبع على
 * الفاتورة ويدخل في رمز «زاتكا»، وإقرار القيمة المضافة يُبنى عليهما. فمكانها
 * القسم الذي تُستعمل فيه.
 *
 * والتعديل يسري على ما يُحرَّر بعده لا على ما حُرِّر: الفاتورة تحمل ضريبتها
 * محسوبةً ومخزّنة، فتغيير النسبة لا يعيد كتابة فواتير شهرٍ قُدِّم إقراره.
 */
class TaxSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = Setting::current();

        return Inertia::render('admin/accounting/TaxSettings', [
            'settings' => [
                'tax_enabled' => (bool) $settings->tax_enabled,
                'tax_number' => $settings->tax_number,
                'tax_rate' => $settings->tax_rate,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tax_enabled' => ['boolean'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        // النسبة الفارغة تعود إلى ١٥٪ لا إلى صفر: الصفر نسبةٌ صحيحة يُقصد
        // إليها، والفراغ سهوٌ — وإسقاطه على الصفر يبيع بلا ضريبة بلا أن يطلبها أحد.
        Setting::current()->fill([
            'tax_enabled' => $data['tax_enabled'] ?? false,
            'tax_number' => $data['tax_number'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? 15,
        ])->save();

        return back()->with('success', 'تم حفظ إعدادات الضريبة');
    }
}
