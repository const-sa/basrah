<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Support\SystemRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UsersController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->get('q', ''));

        $users = User::query()
            ->with(['role:id,name', 'units:id,name,code', 'employee:id,name'])
            ->when($search !== '', fn ($query) => $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->latest('id')
            ->paginate(10)
            ->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->name,
                'role_id' => $u->role_id,
                'employee_id' => $u->employee_id,
                'employee_name' => $u->employee?->name,
                'is_active' => (bool) $u->is_active,
                'is_demo' => (bool) $u->is_demo,
                'has_all_units' => (bool) $u->has_all_units,
                'is_super_admin' => $u->isSuperAdmin(),
                'unit_ids' => $u->units->pluck('id')->all(),
                'unit_names' => $u->units->pluck('name')->all(),
                'systems' => $u->accessibleSystems(),
                'created_at' => $u->created_at?->toDateString(),
            ]);

        $stats = [
            'total' => User::count(),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'scoped' => User::where('has_all_units', false)->whereHas('units')->count(),
        ];

        return Inertia::render('admin/employees/Index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(['id', 'name', 'slug']),
            'units' => Unit::orderBy('sort_order')->get(['id', 'name', 'code', 'type']),
            // الموظفون بلا حساب دخول — هم المرشّحون للربط
            'employees' => Employee::where('is_active', true)
                ->whereDoesntHave('user')
                ->orderBy('name')->get(['id', 'name']),
            'systemLabels' => collect(SystemRegistry::SYSTEMS)
                ->map(fn ($s, $k) => ['key' => $k, 'label' => $s['label']])->values(),
            'filters' => ['q' => $search],
            'stats' => $stats,
            'demo' => DemoAccountsController::panelData(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role_id' => $data['role_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'has_all_units' => $data['has_all_units'] ?? false,
            'email_verified_at' => now(),
        ]);

        $this->syncUnits($user, $data);

        return back()->with('success', 'تم إضافة الموظف بنجاح');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role_id' => $data['role_id'] ?? null,
            'employee_id' => $data['employee_id'] ?? null,
            'is_active' => $data['is_active'] ?? $user->is_active,
            'has_all_units' => $data['has_all_units'] ?? false,
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();
        $this->syncUnits($user, $data);

        return back()->with('success', 'تم تحديث بيانات الموظف');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'role_id' => ['nullable', 'exists:roles,id'],
            'employee_id' => ['nullable', 'exists:employees,id', Rule::unique('users', 'employee_id')->ignore($user?->id)],
            'is_active' => ['boolean'],
            'has_all_units' => ['boolean'],
            'unit_ids' => ['array'],
            'unit_ids.*' => ['integer', 'exists:units,id'],
        ]);
    }

    /**
     * إسناد الوحدات: من يرى كل الوحدات لا يحتاج قائمة، فتُفرَّغ حتى لا
     * تبقى بيانات ميتة تُربك المراجعة لاحقًا.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncUnits(User $user, array $data): void
    {
        if (! empty($data['has_all_units'])) {
            $user->units()->detach();

            return;
        }

        $user->units()->sync($data['unit_ids'] ?? []);
    }

    /**
     * تبديل نطاق «كل الوحدات» بسرعة من الجدول.
     */
    public function toggleScope(User $user): RedirectResponse
    {
        $user->update(['has_all_units' => ! $user->has_all_units]);

        if ($user->has_all_units) {
            $user->units()->detach();
        }

        return back()->with('success', 'تم تغيير نطاق الوحدات');
    }

    public function toggle(User $user): RedirectResponse
    {
        $user->update(['is_active' => ! $user->is_active]);

        return back()->with('success', 'تم تغيير حالة الموظف');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->id === $user->id) {
            return back()->with('warning', 'لا يمكنك حذف حسابك الحالي');
        }

        // Demo accounts are protected here on purpose; they are removed from the trial accounts panel only.
        if ($user->is_demo) {
            return back()->with('warning', 'حساب تجربة محمي من الحذف — استخدم لوحة «حسابات التجربة»');
        }

        $user->delete();

        return back()->with('success', 'تم حذف الموظف');
    }
}
