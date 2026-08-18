<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Role;
use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * النسخ الاحتياطي (§18): نسخةٌ تُؤخذ وتُسجَّل وتُنزَّل بصلاحية.
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RolesSeeder::class]);

        $this->owner = User::factory()->create([
            'name' => 'مالك النظام',
            'role_id' => Role::where('slug', 'super-admin')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }

    protected function tearDown(): void
    {
        // الاختبار لا يترك ملفات على قرص المشروع.
        $directory = storage_path('app/'.config('operations.backup.path', 'backups'));

        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_a_backup_is_taken_and_recorded_with_its_file(): void
    {
        $backup = app(BackupService::class)->run('manual', $this->owner->id);

        $this->assertSame('completed', $backup->status);
        $this->assertSame('manual', $backup->trigger);
        $this->assertSame($this->owner->id, $backup->created_by);
        $this->assertGreaterThan(0, $backup->size);

        $this->assertTrue(File::exists(app(BackupService::class)->path($backup->filename)));
    }

    /**
     * النسخة ملفٌ يحمل البيانات فعلًا لا ملفًا فارغًا باسمٍ صحيح.
     */
    public function test_the_backup_file_contains_the_data(): void
    {
        User::factory()->create(['name' => 'اسمٌ يُبحث عنه في النسخة']);

        $service = app(BackupService::class);
        $backup = $service->run('manual', $this->owner->id);

        $path = $service->path($backup->filename);
        $contents = str_ends_with($path, '.gz') ? gzdecode(File::get($path)) : File::get($path);

        $this->assertStringContainsString('اسمٌ يُبحث عنه في النسخة', (string) $contents);
        $this->assertStringContainsString('users', (string) $contents);
    }

    /**
     * ما زاد عن مدة الاحتفاظ يُحذف بملفه — وإلا امتلأ القرص بصمت.
     */
    public function test_old_backups_are_pruned_with_their_files(): void
    {
        config()->set('operations.backup.keep', 2);

        $service = app(BackupService::class);

        $first = $service->run('manual', $this->owner->id);
        $service->run('manual', $this->owner->id);
        $service->run('manual', $this->owner->id);

        $this->assertSame(2, Backup::completed()->count());
        $this->assertNull(Backup::find($first->id));
        $this->assertFalse(File::exists($service->path($first->filename)));
    }

    public function test_the_screen_lists_backups_and_warns_when_they_are_stale(): void
    {
        $this->actingAs($this->owner)
            ->get('/admin/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('admin/system/Backups')
                ->has('backups.data')
                // لا نسخة بعد — والشاشة تقول ذلك بدل أن تسكت.
                ->where('stats.is_stale', true)
                ->has('stats.cron_hint'),
            );

        app(BackupService::class)->run('schedule');

        $this->get('/admin/backups')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.is_stale', false)
                ->where('stats.total', 1)
                ->where('backups.data.0.exists', true),
            );
    }

    public function test_a_backup_can_be_taken_downloaded_and_deleted_from_the_screen(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/backups')
            ->assertRedirect()
            ->assertSessionHas('success');

        $backup = Backup::firstOrFail();

        $this->get("/admin/backups/{$backup->id}/download")->assertOk();

        $this->delete("/admin/backups/{$backup->id}")->assertSessionHas('success');

        $this->assertNull(Backup::find($backup->id));
        $this->assertFalse(File::exists(app(BackupService::class)->path($backup->filename)));
    }

    /**
     * النسخة هي النظام كله — فبابها محروس، ولا يُنزّلها من لا يملك صلاحيتها.
     */
    public function test_backups_are_guarded_by_permission(): void
    {
        $backup = app(BackupService::class)->run('schedule');

        $cashier = User::factory()->create([
            'role_id' => Role::where('slug', 'cashier')->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($cashier)->get('/admin/backups')->assertForbidden();
        $this->actingAs($cashier)->post('/admin/backups')->assertForbidden();
        $this->actingAs($cashier)->get("/admin/backups/{$backup->id}/download")->assertForbidden();
        $this->actingAs($cashier)->delete("/admin/backups/{$backup->id}")->assertForbidden();
    }

    public function test_the_artisan_command_takes_a_backup(): void
    {
        $this->artisan('backup:run')->assertSuccessful();

        $this->assertSame(1, Backup::completed()->where('trigger', 'schedule')->count());
    }
}
