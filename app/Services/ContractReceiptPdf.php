<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Voucher;
use App\Services\Concerns\ResolvesPublicFiles;
use App\Services\Concerns\UsesCairoFont;
use App\Support\Hijri;
use App\Support\Letterhead;
use App\Support\Tafqeet;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * سند قبضٍ محرَّر على عقد — يُطبع ويُرسل للعميل على واتساب.
 *
 * سند الحجز (BondPdf) يُبنى من دفعات الحجز، وعقد المسابح لا حجز له:
 * دفتره سندات القبض المرحّلة عليه. والورقة بتصميم عرض السعر والعقد
 * اللذين يصدران من الجهة نفسها.
 */
class ContractReceiptPdf
{
    use ResolvesPublicFiles;
    use UsesCairoFont;

    public const DISK = 'public';

    public const DIRECTORY = 'receipts';

    public function render(Contract $contract, Voucher $voucher): string
    {
        $html = View::make('pdf.contract-receipt', $this->viewData($contract, $voucher))->render();

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
                'margin_footer' => 5,
                'tempDir' => storage_path('app/mpdf'),
            ]);

            $mpdf->SetDirectionality('rtl');
            $mpdf->autoLangToFont = false;
            $mpdf->autoScriptToLang = true;

            $title = 'سند قبض - '.$voucher->number;

            $mpdf->SetTitle($title);
            $mpdf->SetAuthor(Letterhead::raw($contract->underPoolsLetterhead())['name']);

            $mpdf->SetHTMLFooter(
                '<div style="text-align:center;font-size:8pt;color:#64748b;border-top:1px solid #e2e8f0;padding-top:3px;">'
                .e($title).' — عقد رقم '.e($contract->number).'</div>'
            );

            $mpdf->WriteHTML($html);

            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (MpdfException $e) {
            throw new RuntimeException('تعذّر توليد ملف السند: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * يحفظ الملف ويعيد مساره. في الاسم مقطعٌ عشوائي: الرابط عامّ، وأرقام
     * السندات متتالية، فاسمٌ برقمها وحده يفتح سندات العملاء لمن يخمّن.
     */
    public function store(Contract $contract, Voucher $voucher): string
    {
        $path = self::DIRECTORY.'/'.pathinfo($this->filename($voucher), PATHINFO_FILENAME)
            .'-'.Str::lower(Str::random(16)).'.pdf';

        Storage::disk(self::DISK)->put($path, $this->render($contract, $voucher));

        return $path;
    }

    /** رابط عام للملف — asset لا Storage::url، لنفس سبب ContractPdf::publicUrl. */
    public function publicUrl(string $path): string
    {
        return asset('storage/'.ltrim($path, '/'));
    }

    public function filename(Voucher $voucher): string
    {
        return 'receipt-'.str_replace(['/', '\\', ' '], '-', $voucher->number).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(Contract $contract, Voucher $voucher): array
    {
        $contract->loadMissing(['client', 'quotation.department']);
        $voucher->loadMissing('paymentMethod:id,name');

        $pools = $contract->underPoolsLetterhead();
        $letterhead = Letterhead::raw($pools);
        $amount = round((float) $voucher->amount, 2);

        // ما قُبض حتى هذا السند لا حتى اليوم: السند الأول يبقى يقول ما بقي
        // يوم كُتب، ولو طُبع بعد أن سُدّد العقد كله.
        $paidToDate = (float) $contract->vouchers()
            ->where('type', 'receipt')
            ->where('status', 'posted')
            ->where('id', '<=', $voucher->id)
            ->sum('amount');

        $total = $contract->totalAmount();

        return [
            'contract' => $contract,
            'voucher' => $voucher,
            'amount' => $amount,
            'amountWords' => Tafqeet::money($amount),
            'dateHijri' => Hijri::short($voucher->voucher_date->toDateString()),
            'total' => $total,
            'paidToDate' => round($paidToDate, 2),
            'remaining' => $total === null ? null : round(max(0, $total - $paidToDate), 2),
            'issuer' => collect(Letterhead::contract($contract->data['org_name'] ?? null, $pools))
                ->only(['business_name', 'phone', 'whatsapp', 'email', 'address', 'tax_number', 'commercial_register', 'manager_name'])
                ->all(),
            'logoPath' => $this->localPath($letterhead['logo_path']),
            'signaturePath' => $this->localPath($letterhead['signature_path']),
            'stampPath' => $this->localPath($letterhead['stamp_path']),
        ];
    }
}
