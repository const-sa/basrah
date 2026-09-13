<?php

namespace App\Services;

use App\Models\BookingPayment;
use App\Models\Setting;
use App\Services\Concerns\ResolvesPublicFiles;
use App\Support\BondPayload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
use RuntimeException;

/**
 * PDF of a receipt voucher, for sending to the client on WhatsApp.
 *
 * Browser printing serves the clerk at the screen; the client needs the
 * voucher itself — a file to open, keep and hold us to.
 *
 * @see ContractPdf same approach, and why mpdf over dompdf
 */
class BondPdf
{
    use ResolvesPublicFiles;

    /** Where vouchers are written on the public disk. */
    public const DISK = 'public';

    public const DIRECTORY = 'bonds';

    /**
     * The PDF as a string of bytes.
     */
    public function render(BookingPayment $payment): string
    {
        $html = View::make('pdf.bond', $this->viewData($payment))->render();

        try {
            // Landscape, as the sheet prints: the voucher is wider than it is
            // tall, and portrait pushes the English column off the line.
            $mpdf = new Mpdf([
                'mode' => 'ar',
                'format' => 'A4-L',
                'default_font' => 'xbriyaz',
                'default_font_size' => 10.5,
                'margin_top' => 10,
                'margin_bottom' => 10,
                'margin_left' => 10,
                'margin_right' => 10,
                'tempDir' => storage_path('app/mpdf'),
            ]);

            $mpdf->SetDirectionality('rtl');
            $mpdf->autoLangToFont = true;
            $mpdf->autoScriptToLang = true;

            $mpdf->SetTitle('سند قبض رقم '.$payment->booking?->reference.'-'.$payment->id);
            $mpdf->SetAuthor((string) (Setting::current()->business_name ?: config('app.name')));

            $mpdf->WriteHTML($html);

            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (MpdfException $e) {
            throw new RuntimeException('تعذّر توليد ملف السند: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * Store on the public disk and return the relative path.
     *
     * Overwritten under the same name: one payment, one voucher. A second
     * copy at a second link leaves the client opening a stale one.
     */
    public function store(BookingPayment $payment): string
    {
        $path = self::DIRECTORY.'/'.$this->filename($payment);

        Storage::disk(self::DISK)->put($path, $this->render($payment));

        return $path;
    }

    /**
     * Public link — the WhatsApp gateway fetches the file from it.
     */
    public function publicUrl(string $path): string
    {
        // asset() not Storage::url(): the latter pins the host to APP_URL, so
        // the link comes out as localhost when served from another host.
        return asset('storage/'.ltrim($path, '/'));
    }

    public function filename(BookingPayment $payment): string
    {
        $reference = str_replace(['/', '\\', ' '], '-', (string) $payment->booking?->reference);

        return 'bond-'.$reference.'-'.$payment->id.'.pdf';
    }

    /**
     * View data — what the screen shows, from the same source.
     *
     * @return array<string, mixed>
     */
    private function viewData(BookingPayment $payment): array
    {
        $settings = Setting::current();

        return [
            'bond' => BondPayload::forPayment($payment),
            'issuer' => BondPayload::issuer($settings),
            // Images go by disk path, not URL — mpdf reads the file directly.
            // The unit's logo wins: the voucher is handed over in that hall.
            'logoPath' => $this->localPath($payment->booking?->unit?->logo_path)
                ?? $this->localPath($settings->logo_path),
            'signaturePath' => $this->localPath($settings->manager_signature_path),
            'stampPath' => $this->localPath($settings->stamp_path),
        ];
    }
}
