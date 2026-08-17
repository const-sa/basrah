<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmployeeGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeGroupsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/employees/Groups', [
            'groups' => EmployeeGroup::withCount('employees')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        EmployeeGroup::create($data);

        return back()->with('success', 'تم إضافة المجموعة بنجاح');
    }

    public function update(Request $request, EmployeeGroup $group): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $group->update($data);

        return back()->with('success', 'تم تحديث المجموعة');
    }

    public function destroy(EmployeeGroup $group): RedirectResponse
    {
        $group->delete();

        return back()->with('success', 'تم حذف المجموعة');
    }
}
