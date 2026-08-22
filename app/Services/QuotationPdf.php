<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\Setting;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * Generate PDF file for Quotations.
 */
class QuotationPdf
{
    public function __construct(private readonly ZatcaQr $zatcaQr) {}

    /**
     * PDF content as a string of bytes.
     */
    public function render(Quotation $quotation): string
    {
        $html = View::make('pdf.quotation', $this->viewData($quotation))->render();

        try {
            $mpdf = new Mpdf([
                'mode' => 'ar',
                'format' => 'A4',
                'default_font' => 'xbriyaz',
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
            $mpdf->autoLangToFont = true;
            $mpdf->autoScriptToLang = true;

            $settings = Setting::current();
            $title = 'عرض سعر - '.$quotation->number;

            $mpdf->SetTitle($title);
            $mpdf->SetAuthor((string) ($settings->business_name ?: config('app.name')));

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
        $quotation->loadMissing(['client', 'user', 'items.item']);

        $settings = Setting::current();
        
        $qrDataUrl = null;
        if ($settings->tax_enabled && $settings->tax_number) {
            $qrDataUrl = $this->zatcaQr->generate(
                sellerName: (string) $settings->business_name,
                taxNumber: (string) $settings->tax_number,
                timestamp: $quotation->created_at,
                totalAmount: (float) $quotation->total_amount,
                taxAmount: (float) $quotation->tax_amount,
            );
        }

        return [
            'quotation' => $quotation,
            'settings' => $settings,
            'qrDataUrl' => $qrDataUrl,
            'logoPath' => $this->localPath($settings->logo_path),
        ];
    }

    /**
     * Local path to the image on disk, if exists — otherwise null.
     */
    private function localPath(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $path), '/');

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
