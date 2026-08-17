<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * رمز الاستجابة السريعة للفاتورة المبسّطة وفق اشتراطات هيئة الزكاة والضريبة.
 *
 * المحتوى ليس نصًّا حرًّا بل خمسة حقول بصيغة TLV (وسم/طول/قيمة) مرمّزة
 * بـ base64: اسم البائع، الرقم الضريبي، الطابع الزمني، الإجمالي شاملًا
 * الضريبة، ثم مبلغ الضريبة. الترتيب والوسوم مثبّتة فلا تُبدَّل.
 */
class ZatcaQr
{
    /**
     * نصّ الرمز — يُقرأ بتطبيق الهيئة كما هو.
     */
    public function payload(
        string $sellerName,
        string $vatNumber,
        string $timestamp,
        float $totalWithVat,
        float $vatAmount,
    ): string {
        $tlv = $this->tag(1, $sellerName)
            .$this->tag(2, $vatNumber)
            .$this->tag(3, $timestamp)
            .$this->tag(4, number_format($totalWithVat, 2, '.', ''))
            .$this->tag(5, number_format($vatAmount, 2, '.', ''));

        return base64_encode($tlv);
    }

    /**
     * الرمز صورةً جاهزة للتضمين في الصفحة والطباعة.
     *
     * SVG لا PNG: يبقى حادًّا عند أي دقة طباعة، ويُضمَّن في الصفحة بلا ملف
     * على القرص فلا يحتاج تنظيفًا لاحقًا.
     */
    public function dataUri(string $payload, int $size = 220): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));

        return 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($payload));
    }

    /**
     * حقل TLV واحد: الوسم بايت، ثم طول القيمة بايت، ثم القيمة.
     */
    private function tag(int $tag, string $value): string
    {
        // بايت الطول لا يتجاوز 255، والقصّ بـ mb_strcut يمنع بتر حرف عربي
        // في منتصفه فيخرج الرمز بمحتوى غير صالح.
        if (strlen($value) > 255) {
            $value = mb_strcut($value, 0, 255, 'UTF-8');
        }

        return chr($tag).chr(strlen($value)).$value;
    }
}
