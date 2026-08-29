<?php

namespace App\Services;

use App\Models\Addon;
use App\Models\EventType;
use App\Models\Package;
use App\Models\Unit;
use App\Models\UnitPrice;
use App\Support\BookingPeriod;
use App\Support\HourlyPeriod;
use App\Support\StayPeriod;
use App\Support\Vat;
use App\Support\Weekdays;
use Carbon\CarbonImmutable;

/**
 * احتساب سعر الحجز (§الطبقة أ - بند 1):
 * أسعار مختلفة لأيام الأسبوع ونهاية الأسبوع، مع الخدمات الإضافية.
 *
 * ولمن أراد تفصيلًا أدق — وهو حال الشاليهات — سعرٌ مستقل لكل يوم من أيام
 * الأسبوع يتقدّم على الثنائية حين يُدخَل، ويُنظر إليه بيوم كل تاريخ على حدة.
 *
 * ترتيب الاحتساب:
 *   السعر الأساسي (وحدة أو مجموع أقسام)
 *   + سعر الباقة + رسم نوع المناسبة + الخدمات − الخصم
 *
 * لا نِسَب مواسم في النظام: السعر المعروض هو السعر المُدخَل. فرق الأعياد
 * والإجازات يُدخَل في تسعيرة القاعة أو في سعر نوع المناسبة صراحةً، لا كنسبة
 * تُضرب في السعر من خلف الموظف فيرى رقمًا غير الذي أدخله.
 */
class BookingPricing
{
    /**
     * أيام نهاية الأسبوع — تُقرأ من افتراضات التشغيل لا من ثابت في الكود،
     * فتغييرها (لإضافة الخميس مثلًا) تعديل إعداد لا تعديل مصدر.
     *
     * @return list<int>
     */
    public static function weekendDays(): array
    {
        return config('operations.weekend_days', [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY]);
    }

    /**
     * احتساب تسعيرة كاملة لحجز مقترح.
     *
     * @param  list<int>  $sectionIds
     * @param  array<int, int>  $addons  معرّف الخدمة => الكمية
     * @param  int  $days  عدد أيام المناسبة — كل يوم يُسعَّر بيومه ثم تُجمع
     * @return array{
     *     base_amount: float, package_amount: float, event_fee_amount: float,
     *     priced_by_event: bool, addons_amount: float, discount_amount: float,
     *     total_amount: float, deposit_amount: float, is_weekend: bool, days: int,
     *     is_taxable: bool, tax_rate: float, net_amount: float, tax_amount: float,
     *     package: array{id: int, name: string, price: float}|null,
     *     event_type: array{id: int, name: string, price: float}|null,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function quote(
        Unit $unit,
        string $scope,
        string $date,
        string $period,
        array $sectionIds = [],
        array $addons = [],
        float $discount = 0,
        ?int $packageId = null,
        ?int $eventTypeId = null,
        int $days = 1,
    ): array {
        $daysCount = BookingPeriod::days($days);

        $eventType = $this->eventTypeFor($unit, $eventTypeId);

        // سعر نوع المناسبة يحل محل تسعيرة القاعة لا يُضاف إليها: «زواج» في
        // هذه القاعة يُباع بسعره المعلن، لا بسعر اليوم زائدَ رسم.
        //
        // ويقتصر ذلك على حجز القاعة كاملة: سعر النوع ثمن القاعة كلها، وتطبيقه
        // على قسم منفرد يبيع نصف القاعة بسعر كلّها. حجز الأقسام يبقى على
        // تسعيرة الأقسام، وتنبّه الواجهة الموظف إلى ذلك.
        $pricedByEvent = $scope === 'whole' && $eventType?->hasOwnPrice();

        // مناسبة تمتد أيامًا تُسعَّر يومًا يومًا ثم تُجمع: نهاية الأسبوع قد تقع
        // داخل المناسبة لا على طرفها، وتسعير الأيام كلها بسعر يوم البداية
        // يخسر المؤسسة فرق الجمعة والسبت في مناسبة تبدأ الأربعاء.
        $isWeekend = false;
        $base = 0.0;
        $dayLines = [];

        foreach (BookingPeriod::dayDates($date, $daysCount) as $day) {
            $dayDate = $day->toDateString();
            $dayIsWeekend = $this->isWeekend($dayDate);
            $isWeekend = $isWeekend || $dayIsWeekend;
            $dayOfWeek = $day->dayOfWeek;

            [$amount, $lines] = match (true) {
                (bool) $pricedByEvent => $this->eventTypeBase($unit, $eventType),
                $scope === 'whole' => $this->wholeUnitBase($unit, $period, $dayIsWeekend, $dayOfWeek),
                default => $this->sectionsBase($unit, $sectionIds, $period, $dayIsWeekend, $dayOfWeek),
            };

            $base += $amount;

            foreach ($lines as $line) {
                // اليوم الواحد يبقى بسطوره كما هي: لا معنى لوسم «يوم واحد»
                // على كل سطر في الحالة الغالبة.
                $dayLines[] = $daysCount === 1
                    ? $line
                    : [...$line, 'day' => $dayDate, 'is_weekend' => $dayIsWeekend];
            }
        }

        $base = round($base, 2);
        $lines = $daysCount === 1 ? $dayLines : $this->foldDayLines($dayLines);

        [$packageTotal, $packageLines, $package] = $this->packageTotal($unit, $packageId);
        [$addonsTotal, $addonLines] = $this->addonsTotal($addons);

        // event_fee_amount لم يعد يُملأ: النوع يحمل السعر الأساسي لا رسمًا
        // فوقه. يبقى العمود صفرًا في الحجوزات الجديدة، وبقيمته في القديمة.
        $eventLines = [];
        $eventFee = 0.0;

        $discount = (float) max(0, round($discount, 2));

        // الضريبة تُضاف فوق المبلغ لا تُستخرج منه: الأسعار المُدخَلة صافية،
        // فيُجمع الصافي أولًا ثم تُحتسب ضريبته وتُضاف، ويخرج الإجمالي شاملًا.
        $net = (float) max(0, round($base + $packageTotal + $eventFee + $addonsTotal - $discount, 2));
        $tax = Vat::breakdown($net);
        $total = $tax['total_amount'];

        return [
            'base_amount' => $base,
            'package_amount' => $packageTotal,
            'event_fee_amount' => $eventFee,
            // تقوله الواجهة للموظف: هل السعر جاء من النوع أم من جدول القاعة؟
            'priced_by_event' => (bool) $pricedByEvent,
            'addons_amount' => $addonsTotal,
            'discount_amount' => $discount,
            // تفصيل الضريبة يصحب الإجمالي ليرى الموظف على الشاشة ما ستحمله
            // فاتورة العميل بالضبط، لا إجماليًا يفاجئه سطرٌ فيه بعد الحفظ.
            ...$tax,
            // والعربون يُحتسب على الإجمالي شاملًا: هو حصةٌ ممّا سيدفعه العميل.
            'deposit_amount' => $this->deposit($unit, $scope, $sectionIds, $period, $total),
            'is_weekend' => $isWeekend,
            'days' => $daysCount,
            'package' => $package ? [
                'id' => $package->id,
                'name' => $package->name,
                'price' => (float) $package->price,
            ] : null,
            'event_type' => $eventType ? [
                'id' => $eventType->id,
                'name' => $eventType->name,
                'price' => (float) $eventType->price,
            ] : null,
            'lines' => [...$lines, ...$packageLines, ...$eventLines, ...$addonLines],
        ];
    }

    /**
     * احتساب تسعيرة إقامة شاليه — السعر مجموع ليالٍ لا سعر يوم.
     *
     * كل ليلة تُسعَّر بتاريخها: نهاية الأسبوع قد تقع داخل الإقامة لا على
     * طرفها، وتسعير الإقامة كلها بسعر ليلة الوصول يخسر المؤسسة ليالي
     * الجمعة والسبت في إقامة تبدأ الأربعاء.
     *
     * الباقات ورسوم المناسبات لا تدخل هنا: هي أدوات القاعة لا الشاليه.
     *
     * @param  list<int>  $sectionIds
     * @param  array<int, int>  $addons
     * @return array{
     *     base_amount: float, package_amount: float, event_fee_amount: float,
     *     addons_amount: float, discount_amount: float,
     *     total_amount: float, deposit_amount: float, is_weekend: bool,
     *     is_taxable: bool, tax_rate: float, net_amount: float, tax_amount: float,
     *     nights: int, weekend_nights: int, average_night: float,
     *     package: null, event_type: null,
     *     lines: list<array<string, mixed>>
     * }
     */
    public function quoteStay(
        Unit $unit,
        string $checkIn,
        string $checkOut,
        array $sectionIds = [],
        array $addons = [],
        float $discount = 0,
    ): array {
        $scope = $sectionIds === [] ? 'whole' : 'sections';
        $nights = StayPeriod::nightDates($checkIn, $checkOut);

        $base = 0.0;
        $lines = [];
        $weekendNights = 0;

        foreach ($nights as $night) {
            $date = $night->toDateString();
            $isWeekend = $this->isWeekend($date);

            $weekendNights += $isWeekend ? 1 : 0;

            [$amount, $nightLines] = $scope === 'whole'
                ? $this->wholeUnitBase($unit, StayPeriod::PERIOD, $isWeekend, $night->dayOfWeek)
                : $this->sectionsBase($unit, $sectionIds, StayPeriod::PERIOD, $isWeekend, $night->dayOfWeek);

            $base += $amount;

            foreach ($nightLines as $line) {
                $lines[] = [
                    ...$line,
                    'night' => $date,
                    'is_weekend' => $isWeekend,
                    'weekday' => Weekdays::label($night->dayOfWeek),
                ];
            }
        }

        [$addonsTotal, $addonLines] = $this->addonsTotal($addons);

        $base = round($base, 2);
        $discount = (float) max(0, round($discount, 2));
        $net = (float) max(0, round($base + $addonsTotal - $discount, 2));
        $tax = Vat::breakdown($net);
        $total = $tax['total_amount'];
        $count = count($nights);

        return [
            'base_amount' => $base,
            'package_amount' => 0.0,
            'event_fee_amount' => 0.0,
            'addons_amount' => $addonsTotal,
            'discount_amount' => $discount,
            ...$tax,
            'deposit_amount' => $this->deposit($unit, $scope, $sectionIds, StayPeriod::PERIOD, $total),
            'is_weekend' => $weekendNights > 0,
            'nights' => $count,
            'weekend_nights' => $weekendNights,
            'average_night' => $count > 0 ? round($base / $count, 2) : 0.0,
            'package' => null,
            'event_type' => null,
            // أسطر الليالي تُدمج في سطر واحد إن تشابهت، وإلا صارت قائمة
            // إقامة شهر ثلاثين سطرًا لا يقرأها أحد.
            'lines' => [...$this->foldNightLines($lines), ...$addonLines],
        ];
    }

    /**
     * تسعيرة حجزٍ بالساعات — مبلغٌ متَّفق عليه لا مبلغٌ محسوب.
     *
     * سائر الأشكال تقرأ سعرها من جدول الوحدة: الليلة بسعر ليلتها، والفترة
     * بسعر فترتها. وهذا الشكل لا جدول له — الساعتان تُختاران لكل حجز على حدة،
     * والمبلغ يُتَّفق عليه في المكالمة. فيدخل المبلغ كما أُدخل ويُحسب عليه ما
     * يُحسب على غيره: الإضافات فوقه، والخصم منه، والضريبة على صافيه.
     *
     * ولا عربون محسوب هنا: العربون نسبةٌ أو مبلغٌ في صف تسعيرة، ولا صف
     * لهذا الشكل. والموظف يقبض ما يتَّفق عليه من لوحة الدفعات.
     *
     * @param  list<int>  $sectionIds
     * @param  array<int, int>  $addons
     * @return array<string, mixed>
     */
    public function quoteHourly(
        Unit $unit,
        float $amount,
        float $hours,
        array $sectionIds = [],
        array $addons = [],
        float $discount = 0,
    ): array {
        $base = round(max(0, $amount), 2);
        $scope = $sectionIds === [] ? 'whole' : 'sections';

        // السطر يقول ما بيع وبكم: القسم يحمل المبلغ كله لأنه المحجوز وحده،
        // وبه يُكتب سعر القسم في جدول الربط كما يُكتب في سائر الأشكال.
        $label = $scope === 'whole'
            ? "{$unit->name} — الوحدة كاملة"
            : "{$unit->name} — ".$unit->sections()->whereIn('id', $sectionIds)->pluck('name')->implode('، ');

        $lines = [[
            'label' => $label.' ('.HourlyPeriod::label($hours).')',
            'amount' => $base,
            ...($scope === 'sections' ? ['section_id' => (int) $sectionIds[0]] : []),
        ]];

        [$addonsTotal, $addonLines] = $this->addonsTotal($addons);

        $discount = (float) max(0, round($discount, 2));
        $net = (float) max(0, round($base + $addonsTotal - $discount, 2));
        $tax = Vat::breakdown($net);

        return [
            'base_amount' => $base,
            'package_amount' => 0.0,
            'event_fee_amount' => 0.0,
            'addons_amount' => $addonsTotal,
            'discount_amount' => $discount,
            ...$tax,
            'deposit_amount' => 0.0,
            'is_weekend' => false,
            'hours' => $hours,
            'hours_label' => HourlyPeriod::label($hours),
            'package' => null,
            'event_type' => null,
            'lines' => [...$lines, ...$addonLines],
        ];
    }

    /**
     * دمج ليالٍ متتالية بنفس السعر في سطر واحد «× عدد الليالي».
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function foldNightLines(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            // المفتاح يجمع القسم والسعر: ليالٍ بنفس السعر لنفس القسم سطر واحد.
            $key = ($line['section_id'] ?? 'whole').'|'.$line['amount'];

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    ...$line,
                    'nights' => 0,
                    'night_price' => $line['amount'],
                    'amount' => 0.0,
                ];
                unset($groups[$key]['night']);
            }

            $groups[$key]['nights']++;
            $groups[$key]['amount'] = round($groups[$key]['amount'] + $line['amount'], 2);
        }

        return array_values(array_map(function (array $g) {
            // مع التسعير اليومي تنفرد كل ليلة بسعرها، فتصير الأسطر «ليلة» و«ليلة»
            // بلا ما يميّزها. اسم اليوم هو التمييز الذي يفهمه العميل في العقد.
            if ($g['nights'] === 1) {
                $g['label'] .= " — ليلة {$g['weekday']}";

                return $g;
            }

            $suffix = $g['is_weekend'] ? ' نهاية أسبوع' : '';
            $g['label'] .= " — {$g['nights']}{$suffix} ليالٍ";

            return $g;
        }, $groups));
    }

    /**
     * دمج أيام المناسبة المتشابهة سعرًا في سطر واحد «× عدد الأيام».
     *
     * مناسبة من ثلاثة أيام بثلاثة أقسام تُنتج تسعة أسطر متطابقة تقريبًا، ولا
     * يقرأها الموظف ولا العميل. الدمج يجمعها في سطر لكل قسم بسعره.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function foldDayLines(array $lines): array
    {
        $groups = [];

        foreach ($lines as $line) {
            // المفتاح يجمع القسم والسعر: أيام بنفس السعر لنفس القسم سطر واحد،
            // ويوم نهاية الأسبوع بسعره الأعلى ينفصل بسطره فيراه العميل.
            $key = ($line['section_id'] ?? 'whole').'|'.$line['amount'];

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    ...$line,
                    'days' => 0,
                    'day_price' => $line['amount'],
                    'amount' => 0.0,
                ];
                unset($groups[$key]['day']);
            }

            $groups[$key]['days']++;
            $groups[$key]['amount'] = round($groups[$key]['amount'] + $line['amount'], 2);
        }

        return array_values(array_map(function (array $g) {
            $suffix = $g['is_weekend'] ? ' نهاية أسبوع' : '';
            $g['label'] .= " — {$g['days']}{$suffix} ".($g['days'] === 1 ? 'يوم' : 'أيام');

            return $g;
        }, $groups));
    }

    /**
     * سعر الوحدة كاملة.
     *
     * @return array{0: float, 1: list<array<string, mixed>>}
     */
    private function wholeUnitBase(Unit $unit, string $period, bool $isWeekend, ?int $dayOfWeek = null): array
    {
        $price = $this->priceRow($unit->id, null, $period);
        $amount = round($price?->priceFor($isWeekend, $dayOfWeek) ?? 0, 2);

        return [$amount, [[
            'label' => "{$unit->name} — الوحدة كاملة",
            'amount' => $amount,
        ]]];
    }

    /**
     * مجموع أسعار الأقسام المختارة.
     *
     * @param  list<int>  $sectionIds
     * @return array{0: float, 1: list<array<string, mixed>>}
     */
    private function sectionsBase(Unit $unit, array $sectionIds, string $period, bool $isWeekend, ?int $dayOfWeek = null): array
    {
        $sections = $unit->sections()->whereIn('id', $sectionIds)->get();

        $total = 0.0;
        $lines = [];

        foreach ($sections as $section) {
            [$price, $fromUnit] = $this->sectionPriceRow($unit, $section->id, $period);
            $amount = round($price?->priceFor($isWeekend, $dayOfWeek) ?? 0, 2);

            $total += $amount;
            $lines[] = [
                // السطر يقول من أين جاء السعر: الموظف يرى مبلغًا لم يُدخله على
                // هذا القسم، فيُنسب إلى الشاليه صراحةً لا يُترك يُخمَّن.
                'label' => $fromUnit
                    ? "{$unit->name} — {$section->name} (بسعر الشاليه)"
                    : "{$unit->name} — {$section->name}",
                'amount' => $amount,
                'section_id' => $section->id,
            ];
        }

        return [round($total, 2), $lines];
    }

    /**
     * سعر الباقة المختارة.
     *
     * الباقة المسنَدة لقاعة أخرى تُتجاهل بدل أن تُسعَّر: اختيارها على هذه
     * القاعة خطأ إدخال، وقبوله يضع في العقد باقة لا تخصّها.
     *
     * @return array{0: float, 1: list<array<string, mixed>>, 2: Package|null}
     */
    private function packageTotal(Unit $unit, ?int $packageId): array
    {
        if (! $packageId) {
            return [0.0, [], null];
        }

        $package = Package::forUnit($unit->id)->find($packageId);

        if (! $package) {
            return [0.0, [], null];
        }

        $amount = round((float) $package->price, 2);

        return [$amount, [[
            'label' => "باقة: {$package->name}",
            'amount' => $amount,
            'package_id' => $package->id,
        ]], $package];
    }

    /**
     * السعر الأساسي حين يحمله نوع المناسبة.
     *
     * السعر يُؤخذ كما أُدخل بلا أي معامل: «زواج» بـ1,000 يظهر في الحجز 1,000.
     * ونهاية الأسبوع لا تدخل هنا أيضًا: سعر النوع سعرٌ واحد أعلنته القاعة،
     * ومن أراد فرقًا بين الأيام تركه بلا سعر وأدخله في تسعيرة القاعة.
     *
     * @return array{0: float, 1: list<array<string, mixed>>}
     */
    private function eventTypeBase(Unit $unit, EventType $type): array
    {
        $amount = round((float) $type->price, 2);

        return [$amount, [[
            'label' => "{$type->name} — {$unit->name}",
            'amount' => $amount,
            'event_type_id' => $type->id,
        ]]];
    }

    /**
     * نوع المناسبة المختار، إن كان فعّالًا ويتبع هذه القاعة.
     *
     * النوع الذي يتبع قاعة أخرى يُهمَل بدل أن يُسعَّر: سعره ثمن تلك القاعة
     * لا هذه، وتطبيقه هنا يبيع القاعة بسعر جارتها.
     */
    private function eventTypeFor(Unit $unit, ?int $eventTypeId): ?EventType
    {
        if (! $eventTypeId) {
            return null;
        }

        return EventType::active()->forUnit($unit->id)->find($eventTypeId);
    }

    /**
     * إجمالي الخدمات الإضافية.
     *
     * @param  array<int, int>  $addons
     * @return array{0: float, 1: list<array<string, mixed>>}
     */
    private function addonsTotal(array $addons): array
    {
        if (empty($addons)) {
            return [0.0, []];
        }

        $models = Addon::whereIn('id', array_keys($addons))->where('is_active', true)->get();

        $total = 0.0;
        $lines = [];

        foreach ($models as $addon) {
            $qty = max(1, (int) ($addons[$addon->id] ?? 1));
            $amount = $addon->totalFor($qty);

            $total += $amount;
            $lines[] = [
                'label' => $addon->name.($qty > 1 ? " × {$qty}" : ''),
                'amount' => $amount,
                'addon_id' => $addon->id,
                'quantity' => $qty,
                'unit_price' => (float) $addon->price,
            ];
        }

        return [round($total, 2), $lines];
    }

    /**
     * العربون المطلوب — يؤخذ من تسعيرة الوحدة، وإن كان الحجز بالأقسام فمن أول قسم مسعَّر.
     *
     * @param  list<int>  $sectionIds
     */
    private function deposit(Unit $unit, string $scope, array $sectionIds, string $period, float $total): float
    {
        // العربون يتبع الصفّ الذي سُعِّر به الحجز نفسه: قسمٌ أخذ سعر الشاليه
        // يأخذ شروط عربونه معه، فلا يُطالَب بعربون صفر على مبلغٍ غير صفر.
        $price = $scope === 'whole' || $sectionIds === []
            ? $this->priceRow($unit->id, null, $period)
            : $this->sectionPriceRow($unit, $sectionIds[0], $period)[0];

        return $price?->depositFor($total) ?? 0.0;
    }

    /**
     * صفّ السعر الذي يُسعَّر به قسمٌ في فترة، ومعه هل جاء من الشاليه لا من القسم.
     *
     * القسم الذي لم يُسعَّر لفترةٍ يأخذ سعر الشاليه فيها: الفترات النهارية
     * تُدخَل على الشاليه مرةً واحدة في الغالب، وحصرُ القسم على صفّه وحده كان
     * يُخرِج إجمالي صفر لحجزٍ فترته مسعَّرة فعلًا. وسعر القسم — متى وُجد —
     * يتقدّم على سعر الشاليه دائمًا.
     *
     * @return array{0: UnitPrice|null, 1: bool}
     */
    private function sectionPriceRow(Unit $unit, int $sectionId, string $period): array
    {
        $row = $this->priceRow($unit->id, $sectionId, $period);

        if ($row?->hasAnyPrice()) {
            return [$row, false];
        }

        $unitRow = $this->priceRow($unit->id, null, $period);

        return $unitRow?->hasAnyPrice() ? [$unitRow, true] : [$row, false];
    }

    private function priceRow(int $unitId, ?int $sectionId, string $period): ?UnitPrice
    {
        return UnitPrice::where('unit_id', $unitId)
            ->where('unit_section_id', $sectionId)
            ->where('period', $period)
            ->where('is_active', true)
            ->first();
    }

    public function isWeekend(string $date): bool
    {
        return in_array(CarbonImmutable::parse($date)->dayOfWeek, self::weekendDays(), true);
    }
}
