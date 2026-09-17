<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Notification;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * صندوق الإشعارات (§14): ما يُعلَن، ومن يسمعه، وما يبقى بعد قراءته.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class, UnitsSeeder::class, BookingSetupSeeder::class]);

        $this->owner = User::factory()->create([
            'name' => 'مالك النظام',
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    public function test_a_new_client_reaches_whoever_keeps_the_client_register(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        $client = Client::create(['name' => 'أبو محمد', 'mobile' => '0551112222', 'is_active' => true]);

        $notice = $clerk->notifications()->sole();

        $this->assertSame('عميل جديد', $notice->title());
        $this->assertSame('clients', $notice->category);
        $this->assertSame('success', $notice->level);
        $this->assertSame('client.created', $notice->event);
        $this->assertSame(Client::class, $notice->subject_type);
        $this->assertSame((string) $client->id, (string) $notice->subject_id);
        $this->assertSame("/admin/clients/{$client->id}", $notice->link());
        $this->assertStringContainsString('أبو محمد', (string) $notice->body());
        $this->assertNull($notice->read_at);
    }

    public function test_the_notice_names_who_did_it_and_reaches_them_too(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551112233', 'is_active' => true]);

        $this->assertSame($this->owner->id, $clerk->notifications()->sole()->actor_id);
        $this->assertSame('مالك النظام', $clerk->notifications()->sole()->actor_name);

        // The inbox is the day's record, so it holds your own deeds as well.
        $this->assertSame(1, $this->owner->notifications()->where('event', 'client.created')->count());
    }

    public function test_your_own_deed_is_marked_as_yours_on_the_screen(): void
    {
        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551112201', 'is_active' => true]);

        $this->actingAs($this->owner)
            ->get('/admin/notifications?category=clients')
            ->assertInertia(fn ($page) => $page
                ->where('notifications.data.0.by_you', true)
                ->where('notifications.data.0.actor_name', 'مالك النظام'));
    }

    public function test_another_hand_s_deed_is_not_marked_as_yours(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551112202', 'is_active' => true]);

        $this->actingAs($clerk)
            ->get('/admin/notifications')
            ->assertInertia(fn ($page) => $page->where('notifications.data.0.by_you', false));
    }

    public function test_a_role_without_the_register_hears_nothing_of_it(): void
    {
        $stranger = $this->staff(['notifications.view', 'dashboard.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551112244', 'is_active' => true]);

        $this->assertSame(0, $stranger->notifications()->count());
    }

    public function test_a_role_without_the_inbox_collects_nothing_it_could_not_open(): void
    {
        $clerk = $this->staff(['clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551112255', 'is_active' => true]);

        $this->assertSame(0, $clerk->notifications()->count());
    }

    public function test_an_inactive_user_is_told_nothing(): void
    {
        $suspended = $this->staff(['notifications.view', 'clients.view']);
        $suspended->update(['is_active' => false]);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551112266', 'is_active' => true]);

        $this->assertSame(0, $suspended->notifications()->count());
    }

    public function test_a_booking_reaches_the_staff_of_its_own_unit_alone(): void
    {
        $here = Unit::where('code', 'HALL-01')->firstOrFail();
        $elsewhere = Unit::where('code', 'HALL-02')->firstOrFail();

        $near = $this->staff(['notifications.view', 'hall_bookings.view'], [$here->id]);
        $far = $this->staff(['notifications.view', 'hall_bookings.view'], [$elsewhere->id]);

        $this->actingAs($this->owner);
        $this->booking($here);

        $this->assertSame(1, $near->notifications()->where('category', 'bookings')->count());
        $this->assertSame(0, $far->notifications()->count());
    }

    public function test_an_ordinary_edit_is_not_announced(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        $client = Client::create(['name' => 'اسم قديم', 'mobile' => '0551112277', 'is_active' => true]);
        $client->update(['name' => 'اسم جديد']);

        $this->assertSame(1, $clerk->notifications()->count());
    }

    public function test_deleting_is_announced_and_points_at_the_archive(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        $client = Client::create(['name' => 'عميل زائل', 'mobile' => '0551112288', 'is_active' => true]);
        $client->delete();

        $notice = $clerk->notifications()->where('event', 'client.deleted')->sole();

        $this->assertSame('حذف عميل', $notice->title());
        $this->assertSame('warning', $notice->level);
        $this->assertSame('/admin/archive', $notice->link());
    }

    public function test_a_write_that_rolls_back_announces_nothing(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);

        try {
            DB::transaction(function () {
                Client::create(['name' => 'لم يكن', 'mobile' => '0551112299', 'is_active' => true]);

                throw new RuntimeException('تراجع');
            });
        } catch (RuntimeException) {
            // The rollback is the point of the test.
        }

        $this->assertSame(0, $clerk->notifications()->count());
    }

    public function test_the_screen_lists_the_inbox_with_its_unread_count(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل أول', 'mobile' => '0551113311', 'is_active' => true]);
        Client::create(['name' => 'عميل ثانٍ', 'mobile' => '0551113322', 'is_active' => true]);

        $this->actingAs($clerk)
            ->get('/admin/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/notifications/Index')
                ->where('stats.total', 2)
                ->where('stats.unread', 2)
                ->where('notificationsUnread', 2)
                ->has('notifications.data', 2));
    }

    public function test_the_screen_filters_by_status_and_category(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view', 'hall_bookings.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551113333', 'is_active' => true]);
        $this->booking(Unit::where('code', 'HALL-01')->firstOrFail());

        $clerk->notifications()->where('category', 'clients')->update(['read_at' => now()]);

        $this->actingAs($clerk)
            ->get('/admin/notifications?status=unread')
            ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
                ->where('notifications.data.0.category', 'bookings'));

        $this->actingAs($clerk)
            ->get('/admin/notifications?category=clients')
            ->assertInertia(fn ($page) => $page->has('notifications.data', 1)
                ->where('notifications.data.0.category', 'clients'));
    }

    public function test_opening_a_notice_reads_it_and_goes_to_the_record(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        $client = Client::create(['name' => 'عميل', 'mobile' => '0551113344', 'is_active' => true]);

        $notice = $clerk->notifications()->sole();

        $this->actingAs($clerk)
            ->get("/admin/notifications/{$notice->id}/read")
            ->assertRedirect("/admin/clients/{$client->id}");

        $this->assertNotNull($notice->fresh()->read_at);
    }

    public function test_one_inbox_cannot_be_reached_from_another(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);
        $other = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551113355', 'is_active' => true]);

        $mine = $clerk->notifications()->sole();

        $this->actingAs($other)->get("/admin/notifications/{$mine->id}/read")->assertNotFound();
        $this->actingAs($other)->delete("/admin/notifications/{$mine->id}")->assertNotFound();

        $this->assertNull($mine->fresh()->read_at);
    }

    public function test_reading_all_and_clearing_the_read_touch_only_your_own_rows(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);
        $other = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551113366', 'is_active' => true]);

        $this->actingAs($clerk)->patch('/admin/notifications/read-all')->assertRedirect();

        $this->assertSame(0, $clerk->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());

        $this->actingAs($clerk)->delete('/admin/notifications/clear')->assertRedirect();

        $this->assertSame(0, $clerk->notifications()->count());
        $this->assertSame(1, $other->notifications()->count());
    }

    public function test_clearing_keeps_what_has_not_been_read(): void
    {
        $clerk = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'مقروء', 'mobile' => '0551113377', 'is_active' => true]);
        Client::create(['name' => 'غير مقروء', 'mobile' => '0551113388', 'is_active' => true]);

        $clerk->notifications()->latest('created_at')->first()->markAsRead();

        $this->actingAs($clerk)->delete('/admin/notifications/clear');

        $this->assertSame(1, $clerk->notifications()->count());
        $this->assertSame(1, $clerk->unreadNotifications()->count());
    }

    public function test_the_inbox_is_closed_to_a_role_without_it(): void
    {
        $stranger = $this->staff(['clients.view']);

        $this->actingAs($stranger)->get('/admin/notifications')->assertForbidden();
    }

    public function test_the_bell_is_dark_for_a_role_without_the_inbox(): void
    {
        $stranger = $this->staff(['clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551113399', 'is_active' => true]);

        $this->actingAs($stranger)
            ->get('/admin/clients')
            ->assertInertia(fn ($page) => $page->where('notificationsUnread', 0));
    }

    public function test_a_notice_is_written_once_per_recipient(): void
    {
        $first = $this->staff(['notifications.view', 'clients.view']);
        $second = $this->staff(['notifications.view', 'clients.view']);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551114411', 'is_active' => true]);

        // Two clerks and the owner who did it — one row each, never two.
        $this->assertSame(3, Notification::where('event', 'client.created')->count());
        $this->assertSame(1, $first->notifications()->count());
        $this->assertSame(1, $second->notifications()->count());
        $this->assertSame(1, $this->owner->notifications()->where('event', 'client.created')->count());
    }

    /**
     * The inbox key predates the inbox, and only the owner's group held it —
     * so every other group would have been told nothing at all.
     */
    public function test_the_seeded_groups_hold_the_inbox(): void
    {
        $accountant = User::factory()->create([
            'role_id' => Role::where('slug', 'accountant')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($this->owner);
        Client::create(['name' => 'عميل', 'mobile' => '0551114422', 'is_active' => true]);

        $this->assertTrue($accountant->hasPermission('notifications.view'));
        $this->assertSame(1, $accountant->notifications()->where('event', 'client.created')->count());
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<int>  $unitIds
     */
    private function staff(array $permissions, array $unitIds = []): User
    {
        $role = Role::create([
            'name' => 'موظف اختبار',
            'slug' => 'test-'.uniqid(),
            'permissions' => $permissions,
        ]);

        $user = User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'has_all_units' => $unitIds === [],
        ]);

        if ($unitIds !== []) {
            $user->units()->sync($unitIds);
        }

        return $user;
    }

    private function booking(Unit $unit): Booking
    {
        $client = Client::withoutEvents(fn () => Client::create([
            'name' => 'صاحب الحجز',
            'mobile' => '05555000'.(++$this->sequence),
            'is_active' => true,
        ]));

        return Booking::create([
            'reference' => 'N-'.$this->sequence,
            'unit_id' => $unit->id,
            'client_id' => $client->id,
            'created_by' => $this->owner->id,
            'scope' => 'whole',
            'period' => 'evening',
            'booking_date' => '2026-08-16',
            'starts_at' => '2026-08-16 18:00:00',
            'ends_at' => '2026-08-16 23:00:00',
            'status' => 'deposit_paid',
            'base_amount' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 400,
        ]);
    }
}
