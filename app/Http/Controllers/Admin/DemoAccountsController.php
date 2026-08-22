<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Manages the throwaway trial ("demo") login accounts shown on the employees page.
 *
 * Demo accounts are ordinary users flagged with `is_demo`. They can be suspended
 * like any employee, but the normal row delete button refuses to touch them —
 * removal only happens through this controller.
 */
class DemoAccountsController extends Controller
{
    /** Shared password for every generated demo account. */
    public const PASSWORD = 'demo12345';

    /**
     * The accounts the admin can generate, keyed by a stable identifier.
     *
     * @return array<int, array{key: string, label: string, name: string, email: string, role_slug: string}>
     */
    public static function presets(): array
    {
        return [
            [
                'key' => 'owner',
                'label' => 'المالك — كل الأنظمة',
                'name' => 'مالك تجريبي',
                'email' => 'demo.owner@example.test',
                'role_slug' => 'super-admin',
            ],
            [
                'key' => 'accountant',
                'label' => 'محاسب — المحاسبة والرواتب',
                'name' => 'محاسب تجريبي',
                'email' => 'demo.accountant@example.test',
                'role_slug' => 'accountant',
            ],
            [
                'key' => 'supervisor',
                'label' => 'مشرف وحدة — الحجوزات والعقود',
                'name' => 'مشرف تجريبي',
                'email' => 'demo.supervisor@example.test',
                'role_slug' => 'unit-supervisor',
            ],
            [
                'key' => 'cashier',
                'label' => 'كاشير — نقطة البيع فقط',
                'name' => 'كاشير تجريبي',
                'email' => 'demo.cashier@example.test',
                'role_slug' => 'cashier',
            ],
        ];
    }

    /**
     * Payload consumed by the "حسابات التجربة" panel on the employees page.
     */
    public static function panelData(): array
    {
        $existing = User::query()
            ->where('is_demo', true)
            ->with('role:id,name')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'role_id', 'is_active']);

        $presets = collect(self::presets())->map(fn ($preset) => [
            'key' => $preset['key'],
            'label' => $preset['label'],
            'name' => $preset['name'],
            'email' => $preset['email'],
            'exists' => User::where('email', $preset['email'])->exists(),
        ])->all();

        return [
            'password' => self::PASSWORD,
            'presets' => $presets,
            'accounts' => $existing->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role?->name,
                'is_active' => (bool) $u->is_active,
            ])->all(),
        ];
    }

    /**
     * حسابات التجربة المفعّلة كما تظهر في شاشة الدخول.
     *
     * تُبنى من الحسابات القائمة لا من القوالب: القالب وصفٌ لما يمكن إنشاؤه،
     * وعرضه في شاشة الدخول يَعِد بحسابٍ لا وجود له. فإذا حُذفت حسابات
     * التجربة من شاشة الموظفين اختفت اللوحة من شاشة الدخول من نفسها.
     *
     * @return array{password: string, accounts: list<array{email: string, label: string, role: string|null}>}|null
     */
    public static function loginPanel(): ?array
    {
        $labels = collect(self::presets())->keyBy('email');

        $accounts = User::query()
            ->where('is_demo', true)
            ->where('is_active', true)
            ->with('role:id,name')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'role_id'])
            ->map(fn (User $u) => [
                'email' => $u->email,
                'label' => $labels[$u->email]['label'] ?? $u->name,
                'role' => $u->role?->name,
            ])
            ->values()
            ->all();

        if ($accounts === []) {
            return null;
        }

        return [
            'password' => self::PASSWORD,
            'accounts' => $accounts,
        ];
    }

    /**
     * Create the selected demo accounts. Presets whose email is already taken are skipped.
     */
    public function store(Request $request): RedirectResponse
    {
        $keys = collect(self::presets())->pluck('key')->all();

        $data = $request->validate([
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*' => ['required', 'string', 'in:'.implode(',', $keys)],
        ], [], ['accounts' => 'الحسابات']);

        $selected = collect(self::presets())
            ->whereIn('key', $data['accounts'])
            ->values();

        $created = 0;
        $skipped = 0;

        foreach ($selected as $preset) {
            if (User::where('email', $preset['email'])->exists()) {
                $skipped++;

                continue;
            }

            User::create([
                'name' => $preset['name'],
                'email' => $preset['email'],
                'password' => Hash::make(self::PASSWORD),
                'role_id' => Role::where('slug', $preset['role_slug'])->value('id'),
                'is_active' => true,
                'is_demo' => true,
                // حسابات العرض ترى كل الوحدات، وإلا خرجت شاشاتها فارغة
                // فيبدو النظام معطلًا وهو سليم.
                'has_all_units' => true,
                'email_verified_at' => now(),
            ]);

            $created++;
        }

        if ($created === 0) {
            return back()->with('warning', 'الحسابات المحددة موجودة بالفعل.');
        }

        $message = "تم تفعيل {$created} حساب تجربة";

        return back()->with('success', $skipped > 0 ? "{$message} (تم تجاهل {$skipped} موجود بالفعل)" : $message);
    }

    /**
     * Delete the selected demo accounts. Only `is_demo` users are ever removed.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ], [], ['ids' => 'الحسابات']);

        $accounts = User::query()
            ->where('is_demo', true)
            ->whereIn('id', $data['ids'])
            ->get();

        // Never let an admin delete the account they are signed in with.
        $self = $accounts->firstWhere('id', $request->user()->id);
        $deletable = $accounts->where('id', '!=', $request->user()->id);

        if ($deletable->isEmpty()) {
            return back()->with('warning', $self
                ? 'لا يمكنك حذف الحساب الذي تستخدمه حالياً.'
                : 'لم يتم العثور على حسابات تجربة مطابقة.');
        }

        $deleted = 0;
        foreach ($deletable as $account) {
            $account->delete();
            $deleted++;
        }

        $message = "تم حذف {$deleted} حساب تجربة";

        return back()->with('success', $self ? "{$message} (تم استثناء حسابك الحالي)" : $message);
    }
}
