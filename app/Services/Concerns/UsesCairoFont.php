<?php

namespace App\Services\Concerns;

use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

/**
 * خط Cairo من Google في ملفات mpdf — خط واجهة النظام نفسه.
 */
trait UsesCairoFont
{
    /**
     * إعدادات mpdf التي تسجّل الخط وتجعله الافتراضي.
     *
     * نسختان ثابتتان (عادي وعريض) مستخرجتان من الخط المتغيّر، فـ mpdf لا
     * يقرأ المحاور المتغيّرة. useOTL يفعّل تشكيل الحروف العربية.
     *
     * @return array<string, mixed>
     */
    protected function cairoConfig(): array
    {
        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        return [
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
        ];
    }
}
