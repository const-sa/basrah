<?php

namespace App\Services;

use App\Models\Sale;
use App\Services\Concerns\ResolvesPublicFiles;
use App\Services\Concerns\UsesCairoFont;
use App\Support\Letterhead;
use App\Support\Vat;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * ملف الفاتورة — يُرسل للعميل على واتساب كما يُرسل العرض والعقد.
 *
 * العميل الذي يسحب طلباته شهريًا يحتاج ورقةً يراجعها ويحفظها، لا سطورًا في
 * رسالة. وهي أخت QuotationPdf في بنائها: نفس الخط ونفس الترويسة ونفس القرص.
 */
class SalePdf
{
    use ResolvesPublicFiles;
    use UsesCairoFont;

    public const DISK = 'public';

    public const DIRECTORY = 'invoices';

    /**
     * يولّد الملف ويحفظه، ويعيد مساره — بوابة الواتساب تحمّله من رابطه.
     *
     * في الاسم مقطعٌ عشوائي: الرابط عامّ وأرقام الفواتير متتالية، فاسمٌ
     * برقمها وحده يفتح فواتير العملاء كلها لمن يخمّن. وهو احتياط
     * QuotationPdf::store نفسه.
     */
    public function store(Sale $sale): string
    {
        $path = self::DIRECTORY.'/'.$this->filename($sale);

        Storage::disk(self::DISK)->put($path, $this->render($sale));

        return $path;
    }

    public function filename(Sale $sale): string
    {
        return 'invoice-'.str_replace(['/', '\\', ' '], '-', $sale->number)
            .'-'.Str::lower(Str::random(16)).'.pdf';
    }

    /** رابط عام للملف — asset لا Storage::url، لنفس سبب ContractPdf::publicUrl. */
    public function publicUrl(string $path): string
    {
        return asset('storage/'.ltrim($path, '/'));
    }

    /**
     * محتوى الملف بايتاتٍ.
     */
    public function render(Sale $sale): string
    {
        $html = View::make('pdf.sale', $this->viewData($sale))->render();

        try {
            $mpdf = new Mpdf([
                'mode' => 'ar',
                'format' => 'A4',
                ...$this->cairoConfig(),
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
            // لا تبديل تلقائي للخط حسب اللغة — نفس سبب QuotationPdf.
            $mpdf->autoLangToFont = false;
            $mpdf->autoScriptToLang = true;

            $title = 'فاتورة - '.$sale->number;

            $mpdf->SetTitle($title);
            $mpdf->SetAuthor(Letterhead::raw($this->isPools($sale))['name']);

            $mpdf->SetHTMLFooter(
                '<div style="text-align:center;font-size:8pt;color:#64748b;border-top:1px solid #e2e8f0;padding-top:3px;">'
                .e($title).' — صفحة {PAGENO} من {nbpg}</div>'
            );

            $mpdf->WriteHTML($html);

            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (MpdfException $e) {
            throw new RuntimeException('تعذّر توليد ملف الفاتورة: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Sale $sale): array
    {
        $sale->loadMissing(['client', 'lines.item', 'department', 'paymentMethod', 'payments.paymentMethod']);

        // فواتير المسابح تصدر باسم مؤسستها وحدها — جهةٌ مستقلة عن الديوان.
        $pools = $this->isPools($sale);
        $raw = Letterhead::raw($pools);

        return [
            'sale' => $sale,
            'issuer' => Letterhead::issuer($pools, Vat::applies() || (float) $sale->tax_amount > 0),
            // المرتجع والمسدَّد والمتبقي تُحسب هنا لا في الورقة: الورقة تعرض.
            'returned' => $sale->returnedAmount(),
            'paid' => (float) $sale->paid_amount,
            'remaining' => $sale->remainingAmount(),
            'paymentMethod' => $sale->methodLabel(),
            // الصور بمساراتها على القرص: mpdf يقرأ الملف مباشرةً.
            'logoPath' => $this->localPath($raw['logo_path']),
            'stampPath' => $this->localPath($raw['stamp_path']),
            'signaturePath' => $this->localPath($raw['signature_path']),
        ];
    }

    private function isPools(Sale $sale): bool
    {
        return (bool) $sale->department?->isPools();
    }
}
