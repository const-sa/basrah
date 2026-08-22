<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\NotificationsController;
use App\Models\Unit;
use App\Support\SiteIdentity;
use Illuminate\Foundation\Inspiring;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        [$message, $author] = str(Inspiring::quotes()->random())->explode('-');

        return array_merge(parent::share($request), [
            'name' => config('app.name'),
            'version' => config('app.version'),
            /**
             * هوية المنشأة (الاسم والشعار) — يعرضها الشريط الجانبي وشاشات
             * الدخول. مشتركة عالميًا لأن الشعار يظهر في كل صفحة: تمريره من
             * كل متحكّم على حدة يعني شاشةً منسيّةً بلا شعار.
             */
            'brand' => fn () => SiteIdentity::brand(),
            'quote' => ['message' => trim($message), 'author' => trim($author)],
            'auth' => [
                'user' => $request->user(),
                'permissions' => fn () => $request->user()
                    ? ($request->user()->isSuperAdmin()
                        ? ['*']
                        : ($request->user()->role?->permissions ?? []))
                    : [],
                // الأنظمة المتاحة للمستخدم — يبني بها الشريط الجانبي نفسه
                'systems' => fn () => $request->user()?->accessibleSystems() ?? [],
                // نطاق الوحدات: null = كل الوحدات، مصفوفة = مقيّد بها
                'units' => fn () => $request->user()?->accessibleUnitIds(),
            ],
            /**
             * الوحدات التي يراها المستخدم — يبني بها الشريط الجانبي مدخلًا
             * لكل وحدة. تُشارَك عالميًا لأن الشريط يظهر في كل صفحة، ولا
             * يصح أن تعتمد على ما ترسله صفحة بعينها.
             */
            'sidebarUnits' => fn () => $request->user()
                ? Unit::visibleTo($request->user())
                    ->where('is_active', true)
                    ->orderBy('type')
                    ->orderBy('sort_order')
                    ->get(['id', 'code', 'name', 'type'])
                    ->map(fn ($u) => [
                        'id' => $u->id,
                        'code' => $u->code,
                        'name' => $u->name,
                        'type' => $u->type,
                    ])
                : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
            'notificationsUnread' => fn () => NotificationsController::unreadCount(),
        ]);
    }
}
