<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Setting;
use App\Support\BookingPeriod;
use App\Support\StayPeriod;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * توليد ملف PDF للعقد (§5 من العرض المعتمد).
 *
 * الطباعة من المتصفح كانت تكفي الموظف الذي أمامه الشاشة، ولا تكفي العميل:
 * ما يُرسل إليه على واتساب يجب أن يكون ملفًا يفتحه ويحفظه ويوقّعه، لا
 * وعدًا بمرفق. ولذلك يُبنى المستند هنا على الخادم.
 *
 * mpdf لا dompdf: العربية تحتاج تشكيل حروف واتجاهًا من اليمين، وdompdf
 * يكتبها حروفًا منفصلة معكوسة. وخط XB Riyaz يأتي مع المكتبة فلا يعتمد
 * المستند على خطٍّ مثبَّت في الخادم.
 */
class ContractPdf
{
    /** مجلد حفظ العقود على القرص العام. */
    public const DISK = 'public';

    public const DIRECTORY = 'contracts';

    public function __construct(private readonly ContractService $contracts) {}

    /**
     * محتوى ملف الـPDF كسلسلة بايتات.
     */
    public function render(Contract $contract): string
    {
        $data = $this->viewData($contract);

        // A form that replaces a printed pad is a different document, not a
        // variant of the standard contract, so each has its own view.
        $view = match (true) {
            $data['isChaletForm'] => 'pdf.contract-stay',
            $data['isInstallationForm'] => 'pdf.contract-installation',
            $data['isMaintenanceForm'] => 'pdf.contract-maintenance',
            default => 'pdf.contract',
        };

        $html = View::make($view, $data)->render();

        try {
            $mpdf = new Mpdf([
                'mode' => 'ar',
                'format' => 'A4',
                'default_font' => 'xbriyaz',
                'default_font_size' => 10.5,
                'margin_top' => 12,
                'margin_bottom' => 14,
                'margin_left' => 12,
                'margin_right' => 12,
                'margin_header' => 5,
                'margin_footer' => 6,
                'tempDir' => storage_path('app/mpdf'),
            ]);

            $mpdf->SetDirectionality('rtl');
            $mpdf->autoLangToFont = true;
            $mpdf->autoScriptToLang = true;

            $mpdf->SetTitle('عقد رقم '.$contract->number);
            $mpdf->SetAuthor((string) (Setting::current()->business_name ?: config('app.name')));

            // ترقيم الصفحات: عقدٌ من ورقتين بلا ترقيم لا يُعرف أنقصت ورقةٌ منه.
            $mpdf->SetHTMLFooter(
                '<div style="text-align:center;font-size:8pt;color:#64748b;border-top:1px solid #e2e8f0;padding-top:3px;">'
                .'عقد رقم '.e($contract->number).' — صفحة {PAGENO} من {nbpg}</div>',
            );

            $mpdf->WriteHTML($html);

            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (MpdfException $e) {
            throw new RuntimeException('تعذّر توليد ملف العقد: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * حفظ الملف على القرص العام وإرجاع مساره النسبي.
     *
     * الملف يُكتب فوق سابقه بنفس الاسم: العقد رقمٌ واحد، ونسخةٌ ثانية له
     * برابط ثانٍ تجعل العميل يفتح ملفًا قديمًا أُرسل إليه قبل التحديث.
     */
    public function store(Contract $contract): string
    {
        $path = self::DIRECTORY.'/'.$this->filename($contract);

        Storage::disk(self::DISK)->put($path, $this->render($contract));

        return $path;
    }

    /**
     * رابط عام للملف — تحتاجه بوابة الواتساب لتحمّله وترسله مرفقًا.
     */
    public function publicUrl(string $path): string
    {
        // asset لا Storage::url: الثانية تثبّت المضيف على APP_URL فيخرج
        // الرابط بـlocalhost حين يُقدَّم النظام من مضيف آخر — وهو نفس
        // السبب الذي بُني عليه Unit::logoUrl().
        return asset('storage/'.ltrim($path, '/'));
    }

    public function filename(Contract $contract): string
    {
        return 'contract-'.str_replace(['/', '\\', ' '], '-', $contract->number).'.pdf';
    }

    /**
     * بيانات القالب — نفس ما تعرضه شاشة العقد، مقروءةً من اللقطة المجمَّدة.
     *
     * @return array<string, mixed>
     */
    private function viewData(Contract $contract): array
    {
        $contract->loadMissing(['booking.unit', 'booking.eventType', 'quotation', 'client', 'template']);

        $settings = Setting::current();

        // اللقطة تكتب «—» مكان الغائب لأنها تُصاغ لتُطبع في نص العقد،
        // فتُعاد هنا إلى null ليخفي القالب حقلها بدل طباعة شرطة.
        $data = collect($contract->data ?? [])
            ->map(fn ($value) => $value === '—' ? null : $value)
            ->all();

        $isStay = $contract->booking?->unit?->type === 'chalet'
            || ($contract->booking?->period === StayPeriod::PERIOD);

        return [
            'contract' => $contract,
            'data' => $data,
            'isStay' => $isStay,
            // Only a chalet is let on the daily-rental form; a hall booked
            // overnight is still a hall contract.
            'isChaletForm' => $contract->booking?->unit?->type === 'chalet',
            // The pools' piping-and-installation pad — its own sheet, with the
            // equipment grid and the two payments the paper form carries.
            'isInstallationForm' => $contract->isInstallationForm(),
            // The monthly-maintenance sheet — the priced lines, the discount
            // under them and the visit schedule in its notes.
            'isMaintenanceForm' => $contract->isMaintenanceForm(),
            // A pools contract prints its priced lines where a rental contract
            // prints unit, period and guest count — different documents behind
            // the same letterhead, numbering and signatures.
            'isQuotation' => $contract->fromQuotation(),
            'lines' => $contract->lines(),
            'unitName' => $data['unit_name'] ?? $contract->booking?->unit?->name,
            'eventName' => $contract->booking?->eventType?->name,
            'periodLabel' => $data['period'] ?? ($contract->booking ? BookingPeriod::label($contract->booking->period) : null),
            // العقود المولّدة قبل فصل الشروط تحمل نصها كاملًا في body.
            'terms' => $contract->terms ?: $contract->body,
            'unitCode' => $contract->booking?->unit?->code,
            'issuer' => [
                'business_name' => $data['org_name'] ?? ($settings->business_name ?: config('app.name')),
                'phone' => $settings->phone,
                // The rental form's header carries two numbers — shown only
                // when the second is genuinely a different one.
                'whatsapp' => $settings->whatsapp !== $settings->phone ? $settings->whatsapp : null,
                'address' => $settings->address,
                'tax_number' => $settings->tax_enabled ? $settings->tax_number : null,
                // The maintenance sheet's letterhead carries the CR number
                // where the installation pad carries the tax number.
                'commercial_register' => $settings->commercial_register,
                'manager_name' => $settings->manager_name,
            ],
            // الصور تُمرَّر بمساراتها على القرص لا بروابطها: mpdf يقرأ الملف
            // مباشرةً، وتحميله عبر HTTP من الخادم نفسه يعلّق التوليد إذا كان
            // العامل الوحيد مشغولًا بالطلب الذي يولّده.
            'logoPath' => $this->localPath($contract->booking?->unit?->logo_path)
                ?? $this->localPath($settings->logo_path),
            'signaturePath' => $this->localPath($settings->manager_signature_path),
            'stampPath' => $this->localPath($settings->stamp_path),
        ];
    }

    /**
     * مسار الصورة على القرص إن وُجدت فعلًا — وإلا null فيتجاهلها القالب.
     */
    private function localPath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');

        // المسارات مخزَّنة تارةً كـ«storage/logos/x.png» وتارةً كـ«logos/x.png»
        // حسب موضع الرفع، فيُجرَّب الاثنان بدل افتراض صيغة واحدة.
        foreach ([public_path($relative), storage_path('app/public/'.$relative)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $stripped = preg_replace('#^storage/#', '', $relative) ?? $relative;
        $candidate = storage_path('app/public/'.$stripped);

        return is_file($candidate) ? $candidate : null;
    }
}
