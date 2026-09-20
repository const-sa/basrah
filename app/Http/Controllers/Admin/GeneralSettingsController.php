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
                // هوية المسابح — تُترك فارغةً فتتبع هوية المنشأة
                'pools_name' => $settings->pools_name,
                'pools_logo_url' => $settings->pools_logo_path ? asset($settings->pools_logo_path) : null,
                'pools_phone' => $settings->pools_phone,
                'phone' => $settings->phone,
                'whatsapp' => $settings->whatsapp,
                'email' => $settings->email,
                'address' => $settings->address,
                'instagram' => $settings->instagram,
                'tiktok' => $settings->tiktok,
                'snapchat' => $settings->snapchat,
                // الضريبة انتقلت إلى المحاسبة (TaxSettingsController): نسبتها
                // تدخل في كل فاتورة، فمكانها القسم الذي يُحاسب بها. والسجل
                // التجاري يبقى هنا — بيانُ هويةٍ يُطبع في الترويسة كالهاتف.
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
            'pools_name' => ['nullable', 'string', 'max:255'],
            'pools_phone' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'whatsapp' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            // Either a handle or a full profile link is accepted.
            'instagram' => ['nullable', 'string', 'max:255'],
            'tiktok' => ['nullable', 'string', 'max:255'],
            'snapchat' => ['nullable', 'string', 'max:255'],
            'commercial_register' => ['nullable', 'string', 'max:100'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'finance_manager_name' => ['nullable', 'string', 'max:255'],
            // قصر الرفع على الصور النقطية فقط ومنع SVG (يمكن أن يحمل سكربت → XSS مخزّن).
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'pools_logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'mimes:png,ico,jpg,jpeg,webp', 'max:1024'],
            'manager_signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'finance_manager_signature' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'stamp' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $settings = Setting::current();

        $settings->fill([
            'business_name' => $data['business_name'] ?? null,
            'pools_name' => $data['pools_name'] ?? null,
            'pools_phone' => $data['pools_phone'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'instagram' => $data['instagram'] ?? null,
            'tiktok' => $data['tiktok'] ?? null,
            'snapchat' => $data['snapchat'] ?? null,
            'commercial_register' => $data['commercial_register'] ?? null,
            'manager_name' => $data['manager_name'] ?? null,
            'finance_manager_name' => $data['finance_manager_name'] ?? null,
        ]);

        if ($request->hasFile('logo')) {
            $settings->logo_path = $this->storeUpload($request->file('logo'), 'logo');
        }

        if ($request->hasFile('pools_logo')) {
            $settings->pools_logo_path = $this->storeUpload($request->file('pools_logo'), 'pools-logo');
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
