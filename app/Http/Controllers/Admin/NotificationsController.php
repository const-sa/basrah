<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Support\NotificationRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * صندوق إشعارات المستخدم (§14).
 *
 * The inbox is personal: every route below reads and writes the signed-in
 * user's own rows and nobody else's, so there is no ownership check to forget
 * — the query never leaves their own relation.
 */
class NotificationsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = $this->filtered($request)
            ->with('actor:id,name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Notification $n) => $this->row($n, $user));

        return Inertia::render('admin/notifications/Index', [
            'notifications' => $notifications,
            'filters' => $request->only(['status', 'category', 'level', 'search']),
            'categories' => collect(NotificationRegistry::CATEGORIES)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'levels' => collect(NotificationRegistry::LEVELS)
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'stats' => [
                'total' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
                // The shelf carrying the most unread is the one worth opening.
                // Reordered first: the relation sorts by date, and a sort
                // column outside the grouping is refused by strict MySQL.
                'by_category' => $user->unreadNotifications()
                    ->reorder()
                    ->selectRaw('category, count(*) as total')
                    ->groupBy('category')
                    ->pluck('total', 'category'),
            ],
        ]);
    }

    /**
     * Opening a notice reads it. The redirect goes to the record when it still
     * has a screen, and back to the inbox when it does not.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $row = $this->own($request)->findOrFail($notification);

        $row->markAsRead();

        return redirect()->to($row->link() ?? route('notifications.index'));
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->reorder()->update(['read_at' => now()]);

        return back()->with('success', 'تم تعليم جميع الإشعارات كمقروءة.');
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->own($request)->findOrFail($notification)->delete();

        return back()->with('success', 'تم حذف الإشعار.');
    }

    /**
     * Clearing keeps the unread: emptying the box should not bury what has not
     * been looked at yet.
     */
    public function clear(Request $request): RedirectResponse
    {
        $request->user()->notifications()->reorder()->whereNotNull('read_at')->delete();

        return back()->with('success', 'تم مسح الإشعارات المقروءة.');
    }

    /**
     * The bell's badge — the middleware shares it on every page.
     */
    public static function unreadCount(?User $user): int
    {
        if (! $user?->hasPermission('notifications.view')) {
            return 0;
        }

        return $user->unreadNotifications()->count();
    }

    /**
     * @return Builder<Notification>
     */
    private function own(Request $request): Builder
    {
        return $request->user()->notifications()->getQuery();
    }

    /**
     * @return Builder<Notification>
     */
    private function filtered(Request $request): Builder
    {
        $status = $request->string('status')->toString();

        return $this->own($request)
            ->when($status === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($status === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->ofCategory($request->string('category')->toString() ?: null)
            ->ofLevel($request->string('level')->toString() ?: null)
            // The text lives inside the JSON, so the search reaches through it
            // by path rather than casting the whole column to a string.
            ->when($request->string('search')->toString(), fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('data->title', 'like', "%{$term}%")
                    ->orWhere('data->body', 'like', "%{$term}%")
                    ->orWhere('actor_name', 'like', "%{$term}%"),
            ));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Notification $notification, User $reader): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->title(),
            'body' => $notification->body(),
            'link' => $notification->link(),
            'category' => $notification->category,
            'category_label' => $notification->categoryLabel(),
            'level' => $notification->level,
            'level_label' => $notification->levelLabel(),
            'event' => $notification->event,
            'actor_name' => $notification->actor_name ?? $notification->actor?->name ?? 'النظام',
            // Your own deeds are in the feed too, so they say so rather than
            // repeating your name down the whole page.
            'by_you' => $notification->actor_id !== null && $notification->actor_id === $reader->getKey(),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->format('Y-m-d H:i'),
            'ago' => $notification->created_at?->diffForHumans(),
        ];
    }
}
