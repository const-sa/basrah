<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\SystemRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RolesController extends Controller
{
    /**
     * الإجراءات القياسية — مصدرها الوحيد سجل الأنظمة.
     */
    public const ACTIONS = SystemRegistry::ACTIONS;

    public function index(): Response
    {
        return Inertia::render('admin/groups/Index', [
            'roles' => Role::withCount('users')->orderBy('id')->get()->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'description' => $r->description,
                'permissions' => $r->permissions ?? [],
                'users_count' => $r->users_count,
                'systems' => $r->accessibleSystems(),
                'is_locked' => $r->slug === 'super-admin',
            ]),
            'systems' => SystemRegistry::forView(),
            'actionLabels' => self::ACTIONS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Role::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'description' => $data['description'] ?? null,
            'permissions' => array_values($data['permissions'] ?? []),
        ]);

        return back()->with('success', 'تم إضافة المجموعة بنجاح');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $data = $this->validated($request);

        // مجموعة المالك محمية: تحتفظ بكل الصلاحيات دائماً.
        $permissions = $role->slug === 'super-admin'
            ? self::permissionKeys()
            : array_values($data['permissions'] ?? []);

        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => $permissions,
        ]);

        return back()->with('success', 'تم تحديث المجموعة');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->slug === 'super-admin') {
            return back()->with('warning', 'لا يمكن حذف مجموعة المالك');
        }

        if ($role->users()->exists()) {
            return back()->with('warning', 'لا يمكن حذف مجموعة مرتبطة بموظفين');
        }

        $role->delete();

        return back()->with('success', 'تم حذف المجموعة');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(self::permissionKeys())],
        ]);
    }

    /**
     * كل مفاتيح الصلاحيات المتاحة في النظام.
     *
     * @return list<string>
     */
    public static function permissionKeys(): array
    {
        return SystemRegistry::permissionKeys();
    }

    /**
     * خريطة مفتاح الصلاحية → التسمية العربية (لعرض الشارات).
     *
     * @return array<string, string>
     */
    public static function permissionLabels(): array
    {
        return SystemRegistry::permissionLabels();
    }

    /**
     * تمثيل الأنظمة الجاهز للواجهة الأمامية (نظام ← وحدات ← إجراءات).
     *
     * @return list<array<string, mixed>>
     */
    public static function systemsForView(): array
    {
        return SystemRegistry::forView();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $slug = $base;
        $i = 1;
        while (Role::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
