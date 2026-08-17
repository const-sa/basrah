<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BookingSetupSeeder;
use Database\Seeders\RolesSeeder;
use Database\Seeders\UnitsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * السجل الرقابي (§14): من أنشأ ومن عدّل ومن حذف، ومتى.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

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

    public function test_creating_a_client_is_recorded_with_the_actor(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/clients', ['name' => 'عميل جديد', 'mobile' => '0551112222'])
            ->assertRedirect();

        $log = AuditLog::where('auditable_type', Client::class)->where('event', 'created')->firstOrFail();

        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame('مالك النظام', $log->actor_name);
        $this->assertStringContainsString('عميل جديد', (string) $log->auditable_label);
        $this->assertSame('عميل جديد', $log->new_values['name']);
    }

    public function test_updating_records_only_the_changed_fields_before_and_after(): void
    {
        $client = Client::create(['name' => 'اسم قديم', 'mobile' => '0551112222', 'is_active' => true]);

        $this->actingAs($this->owner);
        $client->update(['name' => 'اسم جديد']);

        $log = AuditLog::where('auditable_type', Client::class)->where('event', 'updated')->firstOrFail();

        $this->assertSame('اسم قديم', $log->old_values['name']);
        $this->assertSame('اسم جديد', $log->new_values['name']);
        // الجوال لم يتغيّر فلا يُسجَّل — السجل يبيّن ما تغيّر لا ما بقي
        $this->assertArrayNotHasKey('mobile', $log->new_values);
    }

    public function test_no_log_is_written_when_nothing_actually_changed(): void
    {
        $client = Client::create(['name' => 'ثابت', 'mobile' => '0551112222', 'is_active' => true]);

        AuditLog::query()->delete();

        $this->actingAs($this->owner);
        $client->update(['name' => 'ثابت']);

        $this->assertSame(0, AuditLog::where('event', 'updated')->count());
    }

    /**
     * الحذف الناعم حذفٌ في نظر المراجع لا تعديلًا — ولا يُسجَّل مرتين.
     */
    public function test_soft_delete_is_recorded_once_as_a_delete(): void
    {
        $unit = Unit::where('code', 'HALL-01')->firstOrFail();

        AuditLog::query()->delete();

        $this->actingAs($this->owner);
        $unit->delete();

        $logs = AuditLog::where('auditable_type', Unit::class)->get();

        $this->assertCount(1, $logs);
        $this->assertSame('deleted', $logs->first()->event);
    }

    public function test_restoring_is_recorded_as_a_restore(): void
    {
        $unit = Unit::where('code', 'HALL-01')->firstOrFail();

        $this->actingAs($this->owner);
        $unit->delete();

        AuditLog::query()->delete();

        $unit->restore();

        $this->assertSame(1, AuditLog::where('event', 'restored')->count());
    }

    /**
     * السجل الرقابي لا ينسخ الأسرار — وجود المفتاح يكفي لإثبات تغيّره.
     */
    public function test_secrets_are_redacted(): void
    {
        $this->actingAs($this->owner);

        $user = User::factory()->create(['password' => bcrypt('secret-password')]);

        $log = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $user->id)
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame('***', $log->new_values['password']);
    }

    public function test_audit_screen_renders_and_filters_by_event(): void
    {
        $this->actingAs($this->owner);

        Client::create(['name' => 'أ', 'is_active' => true]);
        Client::create(['name' => 'ب', 'is_active' => true])->update(['name' => 'ب معدّل']);

        $this->get('/admin/audit-log?event=updated')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/system/AuditLog')
                ->has('logs.data', 1)
                ->where('logs.data.0.event', 'updated')
                ->has('events', 4)
                ->has('types'),
            );
    }

    public function test_audit_screen_filters_by_type(): void
    {
        $this->actingAs($this->owner);

        Client::create(['name' => 'عميل', 'is_active' => true]);
        Unit::where('code', 'HALL-01')->firstOrFail()->update(['capacity' => 400]);

        $type = urlencode(Client::class);

        $this->get("/admin/audit-log?type={$type}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.subject_label', 'عميل'),
            );
    }

    public function test_export_streams_csv(): void
    {
        $this->actingAs($this->owner);

        Client::create(['name' => 'عميل للتصدير', 'is_active' => true]);

        $this->get('/admin/audit-log/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    /**
     * السجل الرقابي بابٌ محروس: من لا يملك الصلاحية لا يراه.
     */
    public function test_audit_screen_requires_permission(): void
    {
        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/audit-log')->assertForbidden();
    }

    /**
     * الشاشة للقراءة فقط — لا مسار يكتب في السجل أو يمحوه.
     */
    public function test_audit_log_has_no_write_routes(): void
    {
        $this->actingAs($this->owner);

        // POST يطابق مسار العرض بفعلٍ خاطئ (405)، والحذف لا يطابق شيئًا (404).
        $this->post('/admin/audit-log')->assertStatus(405);
        $this->delete('/admin/audit-log/1')->assertNotFound();
    }
}
