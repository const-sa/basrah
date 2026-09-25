<?php

namespace App\Services;

use App\Models\Quotation;
use App\Services\Concerns\ResolvesPublicFiles;
use App\Support\Letterhead;
use App\Support\Vat;
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
    use ResolvesPublicFiles;

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
