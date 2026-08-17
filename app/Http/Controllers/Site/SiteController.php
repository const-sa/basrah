<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Support\BookingPeriod;
use App\Support\SiteIdentity;
use App\Support\StayPeriod;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الواجهة العامة (§12 من العرض المعتمد): عرض الوحدات والأسعار والمواعيد
 * المتاحة لزائرٍ غير مسجّل.
 *
 * كل ما هنا للقراءة فقط، ويُقدَّم للعموم بلا مصادقة. ولذلك تُختار الحقول
 * المعروضة صراحةً بدل تمرير النماذج كما هي: ملاحظات الوحدة الداخلية
 * ومديرها وترميزها المحاسبي لا شأن للزائر بها.
 */
class SiteController extends Controller
{
    public function home(): Response
    {
        return Inertia::render('site/Home', [
            'org' => $this->org(),
            'halls' => $this->units('hall'),
            'chalets' => $this->units('chalet'),
        ]);
    }

    /**
     * صفحة وحدة واحدة: وصفها ومرافقها وأسعارها.
     */
    public function unit(Unit $unit): Response
    {
        abort_unless($unit->is_active, 404);

        $unit->load(['sections' => fn ($q) => $q->where('is_active', true), 'sections.facilities']);

        $isStay = $unit->type === 'chalet';

        return Inertia::render('site/Unit', [
            'org' => $this->org(),
            'unit' => [
                ...$this->unitCard($unit),
                'description' => $unit->description,
                'allows_whole' => $unit->allowsWholeBooking(),
                'allows_sections' => $unit->allowsSectionBooking(),
                'sections' => $unit->sections->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'facilities' => $s->facilities->pluck('name')->all(),
                ])->values(),
            ],
            'isStay' => $isStay,
            'prices' => $this->priceTable($unit, $isStay),
            'periods' => $isStay ? [] : $this->periodOptions(),
        ]);
    }

    /**
     * جدول الأسعار المعروض للزائر.
     *
     * يُعرض «ابتداءً من» لا سعرًا نهائيًا: السعر النهائي يعتمد على اليوم
     * والنطاق والخدمات، وعرض رقمٍ واحد قاطعًا يَعِد الزائر بما قد لا يطابق
     * ما يراه في شاشة الحجز.
     *
     * @return list<array<string, mixed>>
     */
    private function priceTable(Unit $unit, bool $isStay): array
    {
        $rows = UnitPrice::where('unit_id', $unit->id)
            ->whereNull('unit_section_id')
            ->where('is_active', true)
            ->when($isStay, fn ($q) => $q->where('period', StayPeriod::PERIOD))
            ->when(! $isStay, fn ($q) => $q->where('period', '!=', StayPeriod::PERIOD))
            ->get();

        return $rows->map(fn (UnitPrice $p) => [
            'period' => $p->period,
            'label' => $isStay ? StayPeriod::LABEL : BookingPeriod::label($p->period),
            'weekday_price' => (float) $p->weekday_price,
            'weekend_price' => (float) $p->weekend_price,
        ])->values()->all();
    }

    /**
     * @return list<array<string, string>>
     */
    private function periodOptions(): array
    {
        return collect(BookingPeriod::PERIODS)
            ->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'time' => $meta['start'].' — '.$meta['end'],
            ])
            ->values()
            ->all();
    }

    /**
     * الوحدات المعروضة للعموم: الفعّالة وحدها.
     *
     * @return list<array<string, mixed>>
     */
    private function units(string $type): array
    {
        return Unit::where('type', $type)
            ->where('is_active', true)
            ->withCount(['sections' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Unit $u) => $this->unitCard($u))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function unitCard(Unit $unit): array
    {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'type' => $unit->type,
            'capacity' => $unit->capacity,
            'logo_url' => $unit->logoUrl(),
            'sections_count' => $unit->sections_count ?? $unit->sections()->where('is_active', true)->count(),
            'summary' => $unit->description ? mb_substr($unit->description, 0, 160) : null,
            'starting_price' => $this->startingPrice($unit),
        ];
    }

    /**
     * أدنى سعر معلن للوحدة — «ابتداءً من».
     */
    private function startingPrice(Unit $unit): ?float
    {
        $min = UnitPrice::where('unit_id', $unit->id)
            ->where('is_active', true)
            ->where('weekday_price', '>', 0)
            ->min('weekday_price');

        return $min !== null ? (float) $min : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function org(): array
    {
        return SiteIdentity::current();
    }
}
