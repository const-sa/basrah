<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * أنواع المناسبات — لكل قاعة أنواعها وأسعارها، وتظهر في نموذج حجزها.
 *
 * السعر هنا ليس رسمًا فوق تسعيرة القاعة بل ثمن الحجز بهذا النوع: اختيار
 * «زواج» في نموذج الحجز يملأ السعر الأساسي بسعره. والصفر يعني «بلا سعر خاص»
 * فيرجع الحجز إلى جدول تسعيرة القاعة.
 */
class EventTypesController extends Controller
{
    public function index(Request $request): Response
    {
        $halls = Unit::visibleTo($request->user())
            ->where('type', 'hall')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'code']);

        $types = EventType::withCount('bookings')
            ->whereIn('unit_id', $halls->modelKeys())
            ->orderBy('unit_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (EventType $t) => [
                'id' => $t->id,
                'unit_id' => $t->unit_id,
                'name' => $t->name,
                'description' => $t->description,
                'color' => $t->color,
                'price' => (float) $t->price,
                'is_active' => $t->is_active,
                'bookings_count' => $t->bookings_count,
            ]);

        return Inertia::render('admin/bookings/EventTypes', [
            'types' => $types,
            'halls' => $halls,
            'colors' => collect(EventType::COLORS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'stats' => [
                'total' => $types->count(),
                'priced' => $types->where('price', '>', 0)->count(),
                'inactive' => $types->where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $this->authorizeHall($request, (int) $data['unit_id']);

        EventType::create([
            ...$data,
            // الترتيب داخل القاعة لا عبر القاعات: كل قاعة ترتّب أنواعها.
            'sort_order' => (EventType::forUnit((int) $data['unit_id'])->max('sort_order') ?? 0) + 1,
        ]);

        return back()->with('success', 'تم إضافة نوع المناسبة');
    }

    public function update(Request $request, EventType $eventType): RedirectResponse
    {
        $data = $this->validated($request, $eventType);

        $this->authorizeHall($request, (int) $data['unit_id']);
        $this->authorizeHall($request, $eventType->unit_id);

        $eventType->update($data);

        return back()->with('success', 'تم تحديث نوع المناسبة');
    }

    public function toggle(Request $request, EventType $eventType): RedirectResponse
    {
        $this->authorizeHall($request, $eventType->unit_id);

        $eventType->update(['is_active' => ! $eventType->is_active]);

        return back()->with('success', 'تم تغيير حالة نوع المناسبة');
    }

    /**
     * النوع المستخدم في حجوزات لا يُحذف — حذفه يمسح تصنيفها من السجل.
     * التعطيل يخفيه عن الحجوزات الجديدة ويُبقي القديمة مفهومة.
     */
    public function destroy(Request $request, EventType $eventType): RedirectResponse
    {
        $this->authorizeHall($request, $eventType->unit_id);

        if ($eventType->bookings()->exists()) {
            return back()->with('warning', 'النوع مستخدم في حجوزات — عطّله بدل حذفه.');
        }

        $eventType->delete();

        return back()->with('success', 'تم حذف نوع المناسبة');
    }

    /**
     * القاعة يجب أن تكون قاعةً فعلًا وضمن نطاق المستخدم.
     */
    private function authorizeHall(Request $request, int $unitId): void
    {
        if (! $request->user()?->canAccessUnit($unitId)) {
            abort(403, 'ليس لديك صلاحية العمل على هذه القاعة.');
        }

        if (Unit::whereKey($unitId)->value('type') !== 'hall') {
            abort(422, 'أنواع المناسبات تخصّ القاعات وحدها.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?EventType $eventType = null): array
    {
        return $request->validate([
            'unit_id' => ['required', 'exists:units,id'],
            'name' => [
                'required', 'string', 'max:255',
                // الاسم يتكرر بين القاعات ولا يتكرر داخل القاعة الواحدة.
                Rule::unique('event_types', 'name')
                    ->where('unit_id', $request->integer('unit_id'))
                    ->ignore($eventType),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', Rule::in(array_keys(EventType::COLORS))],
            'price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
