<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * التحكم بمرافق الوحدات (مسبح، مطبخ، موقف…).
 */
class FacilitiesController extends Controller
{
    /**
     * أيقونات مقترحة — أسماء lucide المتاحة في الواجهة.
     */
    public const ICONS = [
        'Waves', 'ChefHat', 'Car', 'DoorOpen', 'Trees', 'Dumbbell',
        'Wifi', 'Tv', 'Wind', 'Flame', 'Baby', 'Volleyball',
    ];

    public function index(): Response
    {
        return Inertia::render('admin/units/Facilities', [
            'facilities' => Facility::orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Facility $f) => [
                    'id' => $f->id,
                    'name' => $f->name,
                    'icon' => $f->icon,
                    'description' => $f->description,
                    'is_active' => $f->is_active,
                    'sections_count' => $f->sections()->count(),
                    'units_count' => $f->unitsCount(),
                ]),
            'icons' => self::ICONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Facility::create([
            ...$this->validated($request),
            'sort_order' => (Facility::max('sort_order') ?? 0) + 1,
        ]);

        return back()->with('success', 'تم إضافة المرفق');
    }

    public function update(Request $request, Facility $facility): RedirectResponse
    {
        $facility->update($this->validated($request, $facility));

        return back()->with('success', 'تم تحديث المرفق');
    }

    public function destroy(Facility $facility): RedirectResponse
    {
        if ($facility->sections()->exists()) {
            return back()->with('warning', 'لا يُحذف مرفق مسنَد لأقسام — عطّله بدل حذفه.');
        }

        $facility->delete();

        return back()->with('success', 'تم حذف المرفق');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Facility $facility = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('facilities', 'name')->ignore($facility?->id)],
            'icon' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ]);
    }
}
