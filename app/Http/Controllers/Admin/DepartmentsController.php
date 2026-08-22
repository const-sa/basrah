<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentsController extends Controller
{
    public function index(): Response
    {
        $departments = Department::orderBy('name')
            ->get()
            ->map(fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'is_active' => $d->is_active,
                'created_at' => $d->created_at?->format('Y-m-d'),
            ]);

        return Inertia::render('admin/departments/Index', [
            'departments' => $departments,
            'stats' => [
                'total' => Department::count(),
                'active' => Department::where('is_active', true)->count(),
                'inactive' => Department::where('is_active', false)->count(),
            ],
        ]);
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        $department->update([
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? $department->is_active,
        ]);

        return back()->with('success', 'تم تحديث القسم');
    }
}
