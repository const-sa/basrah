<?php

namespace App\Services;

use App\Models\Quotation;
use App\Services\Concerns\ResolvesPublicFiles;
use App\Support\Letterhead;
use App\Support\Vat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * Generate PDF file for Quotations.
 */
class QuotationPdf
{
    use ResolvesPublicFiles;

    public const DISK = 'public';

    public const DIRECTORY = 'quotations';

    /**
     * يولّد الملف ويحفظه، ويعيد مساره — بوابة الواتساب تحمّله من رابطه
     * لترسله مرفقًا (كما يُرسل العقد).
     *
     * في الاسم مقطعٌ عشوائي: الرابط عامّ، وأرقام العروض متتالية، فاسمٌ
     * برقمها وحده يفتح عروض العملاء كلها لمن يخمّن.
     */
    public function store(Quotation $quotation): string
    {
        $path = self::DIRECTORY.'/quotation-'.str_replace(['/', '\\', ' '], '-', $quotation->number)
            .'-'.Str::lower(Str::random(16)).'.pdf';

        Storage::disk(self::DISK)->put($path, $this->render($quotation));

        return $path;
    }

    /** رابط عام للملف — asset لا Storage::url، لنفس سبب ContractPdf::publicUrl. */
    public function publicUrl(string $path): string
    {
        return asset('storage/'.ltrim($path, '/'));
    }

    /**
     * PDF content as a string of bytes.
     */
    public function render(Quotation $quotation): string
    {
        $html = View::make('pdf.quotation', $this->viewData($quotation))->render();

        try {
            $defaults = (new ConfigVariables)->getDefaults();
            $fonts = (new FontVariables)->getDefaults();

            $mpdf = new Mpdf([
                'mode' => 'ar',
                'format' => 'A4',
                // خط Cairo من Google — خط واجهة النظام نفسه. نسختان ثابتتان
                // (عادي وعريض) مستخرجتان من الخط المتغيّر، فـ mpdf لا يقرأ
                // المحاور المتغيّرة. useOTL يفعّل تشكيل الحروف العربية.
                'fontDir' => [...$defaults['fontDir'], resource_path('fonts/cairo')],
                'fontdata' => $fonts['fontdata'] + [
                    'cairo' => [
                        'R' => 'Cairo-Regular.ttf',
                        'B' => 'Cairo-Bold.ttf',
                        'useOTL' => 0xFF,
                        'useKashida' => 75,
                    ],
                ],
                'default_font' => 'cairo',
                'default_font_size' => 10,
                'margin_top' => 10,
                'margin_bottom' => 10,
                'margin_left' => 10,
                'margin_right' => 10,
                'margin_header' => 5,
                'margin_footer' => 5,
                'tempDir' => storage_path('app/mpdf'),
            ]);

            $mpdf->SetDirectionality('rtl');
            // لا تبديل تلقائي للخط حسب اللغة: كان يعيد العربية إلى xbriyaz
            // فوق Cairo. Cairo يحمل العربية واللاتينية معًا.
            $mpdf->autoLangToFont = false;
            $mpdf->autoScriptToLang = true;

            $title = 'عرض سعر - '.$quotation->number;

            $mpdf->SetTitle($title);
            $mpdf->SetAuthor(Letterhead::raw($this->isPools($quotation))['name']);

            $mpdf->SetHTMLFooter(
                '<div style="text-align:center;font-size:8pt;color:#64748b;border-top:1px solid #e2e8f0;padding-top:3px;">'
                .e($title).' — صفحة {PAGENO} من {nbpg}</div>'
            );

            $mpdf->WriteHTML($html);

            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (MpdfException $e) {
            throw new RuntimeException('تعذّر توليد ملف عرض السعر: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Template data
     *
     * @return array<string, mixed>
     */
    private function viewData(Quotation $quotation): array
    {
        $quotation->loadMissing(['client', 'user', 'items.item', 'department']);

        // عرض المسابح يصدر باسم مؤسستها وحدها — جهةٌ مستقلة عن الديوان.
        // ولا رمز زكاة هنا: العرض ليس فاتورة (راجع QuotationController::issuer).
        $pools = $this->isPools($quotation);

        return [
            'quotation' => $quotation,
            'issuer' => Letterhead::issuer($pools, Vat::applies() || (float) $quotation->tax_amount > 0),
            'logoPath' => $this->localPath(Letterhead::raw($pools)['logo_path']),
        ];
    }

    private function isPools(Quotation $quotation): bool
    {
        return (bool) $quotation->department?->isPools();
    }
}
