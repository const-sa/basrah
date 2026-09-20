<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\SystemRegistry;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صفحة القسم — شاشاته مبسوطةً بأيقوناتها وأسمائها بدل قائمةٍ جانبية تُفتح وتُغلق.
 *
 * القائمة الجانبية تُخفي شاشات القسم خلف نقرةِ فتحٍ ثم قائمةٍ ضيّقة تُقرأ
 * سطرًا سطرًا. والمحاسبة وحدها ثلاث عشرة شاشة، فالبحث فيها بالعين أبطأ من
 * الوصول إليها. هنا تُفتح كلها دفعةً واحدة في مساحة الصفحة.
 *
 * والشاشات نفسها لا تُكتب هنا: مصدرها شجرة التنقّل في الواجهة
 * (useNavigation)، فما يُضاف إلى القائمة يظهر في هذه الصفحة من تلقائه،
 * ولا تبقى صفحةٌ ناقصةً شاشةً نسيها من أضافها.
 */
class SectionHubController extends Controller
{
    public function accounting(): Response
    {
        return $this->show('accounting');
    }

    private function show(string $key): Response
    {
        $system = SystemRegistry::SYSTEMS[$key] ?? [];

        return Inertia::render('admin/SectionHub', [
            'section' => [
                'key' => $key,
                // The sidebar entry this page mirrors: the client finds the
                // group by its href, so the two cannot drift apart.
                'href' => '/admin/'.$key,
                'label' => $system['label'] ?? $key,
                'description' => $system['description'] ?? null,
            ],
        ]);
    }
}
