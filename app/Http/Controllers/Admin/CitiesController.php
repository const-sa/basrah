<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CitiesController extends Controller
{
    public function index(): Response
    {
        // عدد العملاء لكل مدينة في استعلام واحد مُجمَّع (بدل استعلام لكل مدينة → N+1).
        $clientCounts = Client::query()
            ->whereNotNull('city')
            ->groupBy('city')
            ->selectRaw('city, COUNT(*) as aggregate')
            ->pluck('aggregate', 'city');

        $cities = City::orderBy('name')
            ->get()
            ->map(fn (City $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'is_active' => $c->is_active,
                // عدد العملاء المرتبطين بالمدينة (بالاسم) — يمنع الحذف العرضي لمدينة مستخدمة.
                'clients_count' => (int) ($clientCounts[$c->name] ?? 0),
                'created_at' => $c->created_at?->format('Y-m-d'),
            ]);

        return Inertia::render('admin/cities/Index', [
            'cities' => $cities,
            'stats' => [
                'total' => City::count(),
                'active' => City::where('is_active', true)->count(),
                'inactive' => City::where('is_active', false)->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:cities,name'],
            'is_active' => ['boolean'],
        ]);

        City::create([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', 'تم إضافة المدينة بنجاح');
    }

    public function update(Request $request, City $city): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:cities,name,'.$city->id],
            'is_active' => ['boolean'],
        ]);

        $oldName = $city->name;

        $city->update([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? $city->is_active,
        ]);

        // مزامنة اسم المدينة لدى العملاء المرتبطين عند إعادة التسمية.
        if ($oldName !== $city->name) {
            Client::where('city', $oldName)->update(['city' => $city->name]);
        }

        return back()->with('success', 'تم تحديث المدينة');
    }

    public function toggle(City $city): RedirectResponse
    {
        $city->update(['is_active' => ! $city->is_active]);

        return back()->with('success', 'تم تغيير حالة المدينة');
    }

    public function destroy(City $city): RedirectResponse
    {
        if (Client::where('city', $city->name)->exists()) {
            return back()->with('error', 'لا يمكن حذف مدينة مرتبطة بعملاء');
        }

        $city->delete();

        return back()->with('success', 'تم حذف المدينة');
    }
}
