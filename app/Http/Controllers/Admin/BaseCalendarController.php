<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Request as HttpRequest;

/**
 * ما يشترك فيه تقويم القاعات وتقويم الشاليهات.
 *
 * التقويمان منفصلان لأن ما يُعرض في الخلية مختلف جوهريًا: خلية القاعة تحمل
 * فترة داخل يوم (صباحي/مسائي/يوم كامل)، وخلية الشاليه جزءٌ من إقامة تمتد
 * عبر أيام. دمجهما في شبكة واحدة يجعل كل صف يعني شيئًا مختلفًا عن جاره.
 * أما شبكة الأيام واختيار الشهر وقصر الوحدات على صلاحية المستخدم فواحدة.
 */
abstract class BaseCalendarController extends Controller
{
    /**
     * نوع الوحدات التي يعرضها التقويم: hall أو chalet.
     */
    abstract protected function unitType(): string;

    /**
     * الشهر المعروض ومداه.
     *
     * @return array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    protected function month(Request $request): array
    {
        $month = $request->string('month')->toString() ?: now()->format('Y-m');
        $start = CarbonImmutable::parse($month.'-01')->startOfMonth();

        return [$month, $start, $start->endOfMonth()];
    }

    /**
     * وحدات هذا النوع التي يصل إليها المستخدم.
     *
     * @return Collection<int, Unit>
     */
    protected function units(HttpRequest $request): Collection
    {
        return Unit::visibleTo($request->user())
            ->where('is_active', true)
            ->where('type', $this->unitType())
            // is_active ضمن الأعمدة لا زيادة: التقويم الشهري يستبعد القسم
            // الموقوف من الأقسام المتاحة، وغيابه يجعل كل قسم يبدو موقوفًا.
            ->with('sections:id,unit_id,name,gender,is_active')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * تمثيل الوحدات وأقسامها كما تحتاجه صفوف الشبكة.
     *
     * @param  Collection<int, Unit>  $units
     * @return list<array<string, mixed>>
     */
    protected function unitRows(Collection $units): array
    {
        return $units->map(fn (Unit $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'code' => $u->code,
            'type' => $u->type,
            'sections' => $u->sections->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
        ])->values()->all();
    }

    /**
     * أيام الشهر مع تمييز نهاية الأسبوع لتلوين الأعمدة.
     *
     * @return list<array<string, mixed>>
     */
    protected function days(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'day' => $day->day,
                'weekday' => $day->locale('ar')->isoFormat('ddd'),
                'is_weekend' => in_array($day->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true),
                'is_today' => $day->isToday(),
            ];
        }

        return $days;
    }
}
