<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Add-on services sold on top of a booking (catering, cleaning, lighting…).
 *
 * One catalogue for both activities: the hall and chalet booking forms read
 * the same list, so the prices live here rather than per unit. Until this
 * screen existed the rows could only be changed by re-running the seeder.
 *
 * `pricing` is a label, not a rule: BookingPricing always charges
 * price × the quantity typed on the booking. What the mode does is tell
 * whoever types that quantity what it should count — one unit, guests, or
 * hours — so the same 45 riyals is not read as a flat fee by one clerk and
 * a per-head rate by another.
 */
class AddonsController extends Controller
{
    public function index(): Response
    {
        $addons = Addon::withCount('bookings')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Addon $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'price' => (float) $a->price,
                'pricing' => $a->pricing,
                'pricing_label' => Addon::PRICING[$a->pricing] ?? $a->pricing,
                'description' => $a->description,
                'is_active' => $a->is_active,
                'bookings_count' => $a->bookings_count,
            ]);

        return Inertia::render('admin/bookings/Addons', [
            'addons' => $addons,
            'pricingModes' => collect(Addon::PRICING)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])
                ->values(),
            'stats' => [
                'total' => $addons->count(),
                'active' => $addons->where('is_active', true)->count(),
                'inactive' => $addons->where('is_active', false)->count(),
                'used' => $addons->where('bookings_count', '>', 0)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Addon::create([
            ...$this->validated($request),
            'sort_order' => (Addon::max('sort_order') ?? 0) + 1,
        ]);

        return back()->with('success', 'تم إضافة الخدمة');
    }

    public function update(Request $request, Addon $addon): RedirectResponse
    {
        $addon->update($this->validated($request, $addon));

        return back()->with('success', 'تم تحديث الخدمة');
    }

    public function toggle(Addon $addon): RedirectResponse
    {
        $addon->update(['is_active' => ! $addon->is_active]);

        return back()->with(
            'success',
            $addon->is_active
                ? "تم تفعيل «{$addon->name}» وصارت تظهر في نماذج الحجز"
                : "تم تعطيل «{$addon->name}» — لن تظهر في الحجوزات الجديدة",
        );
    }

    /**
     * An add-on already sold on a booking is not deleted: removing it would
     * strip a line the invoice and its journal entry were built from.
     * Disabling hides it from new bookings and leaves the old ones readable.
     */
    public function destroy(Addon $addon): RedirectResponse
    {
        if ($addon->bookings()->exists()) {
            return back()->with('warning', 'الخدمة مستخدمة في حجوزات — عطّلها بدل حذفها.');
        }

        $addon->delete();

        return back()->with('success', 'تم حذف الخدمة');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Addon $addon = null): array
    {
        return $request->validate([
            // The booking form lists add-ons by name alone, so two rows sharing
            // one name would be indistinguishable at the point of sale.
            'name' => ['required', 'string', 'max:255', Rule::unique('addons', 'name')->ignore($addon)],
            'price' => ['required', 'numeric', 'min:0'],
            'pricing' => ['required', Rule::in(array_keys(Addon::PRICING))],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
