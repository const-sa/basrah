<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

class GeneralSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = Setting::current();

        return Inertia::render('admin/settings/General', [
            'settings' => [
                'business_name' => $settings->business_name,
                'logo_url' => $settings->logo_path ? asset($settings->logo_path) : null,
                'favicon_url' => $settings->favicon_path ? asset($settings->favicon_path) : null,
                'phone' => $settings->phone,
                'whatsapp' => $settings->whatsapp,
                'email' => $settings->email,
                'address' => $settings->address,
                'tax_enabled' => $settings->tax_enabled,
                'tax_number' => $settings->tax_number,
                'tax_rate' => $settings->tax_rate,
                'commercial_register' => $settings->commercial_register,
                'manager_name' => $settings->manager_name,
                'manager_signature_url' => $settings->manager_signature_path ? asset($settings->manager_signature_path) : null,
                'finance_manager_name' => $settings->finance_manager_name,
                'finance_manager_signature_url' => $settings->finance_manager_signature_path ? asset($settings->finance_manager_signature_path) : null,
                'stamp_url' => $settings->stamp_path ? asset($settings->stamp_path) : null,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_enabled' => ['boolean'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commercial_register' => ['nullable', 'string', 'max:100'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'finance_manager_name' => ['nullable', 'string', 'max:255'],
            // قصر الرفع على الصور النقطية فقط ومنع SVG (يمكن أن يحمل سكربت → XSS مخزّن).
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'mimes:png,ico,jpg,jpeg,webp', 'max:1024'],
            'manager_signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'finance_manager_signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'stamp' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $settings = Setting::current();

        $settings->fill([
            'business_name' => $data['business_name'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'tax_enabled' => $data['tax_enabled'] ?? false,
            'tax_number' => $data['tax_number'] ?? null,
            'tax_rate' => $data['tax_rate'] ?? 15,
            'commercial_register' => $data['commercial_register'] ?? null,
            'manager_name' => $data['manager_name'] ?? null,
            'finance_manager_name' => $data['finance_manager_name'] ?? null,
        ]);

        if ($request->hasFile('logo')) {
            $settings->logo_path = $this->storeUpload($request->file('logo'), 'logo');
        }

        if ($request->hasFile('favicon')) {
            $settings->favicon_path = $this->storeUpload($request->file('favicon'), 'favicon');
        }

        if ($request->hasFile('manager_signature')) {
            $settings->manager_signature_path = $this->storeUpload($request->file('manager_signature'), 'manager-signature');
        }

        if ($request->hasFile('finance_manager_signature')) {
            $settings->finance_manager_signature_path = $this->storeUpload($request->file('finance_manager_signature'), 'finance-signature');
        }

        if ($request->hasFile('stamp')) {
            $settings->stamp_path = $this->storeUpload($request->file('stamp'), 'stamp');
        }

        $settings->save();

        return back()->with('success', 'تم حفظ الإعدادات بنجاح');
    }

    /**
     * رفع الملف إلى public/uploads وإرجاع المسار النسبي (يُخدم مباشرة دون symlink).
     */
    private function storeUpload(UploadedFile $file, string $prefix): string
    {
        $dir = public_path('uploads');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // الامتداد يُشتقّ من المحتوى الفعلي للملف (لا من اسم العميل)،
        // والاسم عشوائي غير قابل للتخمين لمنع التخمين/الاستبدال.
        $ext = $file->extension() ?: 'png';
        $name = $prefix.'-'.bin2hex(random_bytes(8)).'.'.$ext;
        $file->move($dir, $name);

        return 'uploads/'.$name;
    }
}
