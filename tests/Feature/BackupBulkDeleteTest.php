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
 * حذف ما حُدِّد من النسخ دفعةً واحدة — بملفاتها، وبالصلاحية نفسها.
 *
 * يعمل في مجلدٍ خاص به كي لا يمسّ النسخ الحقيقية على قرص المشروع.
 */
class BackupBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECTORY = 'backups-bulk-test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['operations.backup.path' => self::DIRECTORY]);
        $this->seed([RolesSeeder::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/'.self::DIRECTORY));

        parent::tearDown();
    }

    public function test_the_selected_backups_are_deleted_with_their_files(): void
    {
        [$first, $second, $kept] = [$this->backup('a'), $this->backup('b'), $this->backup('c')];

        $this->actingAs($this->userWithRole('super-admin'))
            ->delete('/admin/backups/bulk', ['ids' => [$first->id, $second->id]])
            ->assertRedirect()
            ->assertSessionHas('success', 'تم حذف 2 نسخة احتياطية');

        $this->assertNull(Backup::find($first->id));
        $this->assertNull(Backup::find($second->id));
        $this->assertNotNull(Backup::find($kept->id));

        $service = app(BackupService::class);
        $this->assertFalse(File::exists($service->path($first->filename)));
        $this->assertFalse(File::exists($service->path($second->filename)));
        $this->assertTrue(File::exists($service->path($kept->filename)));
    }

    public function test_bulk_delete_is_guarded_by_permission(): void
    {
        $backup = $this->backup('a');

        $this->actingAs($this->userWithRole('cashier'))
            ->delete('/admin/backups/bulk', ['ids' => [$backup->id]])
            ->assertForbidden();

        $this->assertNotNull(Backup::find($backup->id));
    }

    private function backup(string $name): Backup
    {
        $filename = "backup-{$name}.sql.gz";
        $path = app(BackupService::class)->path($filename);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'dump');

        return Backup::create([
            'filename' => $filename,
            'disk' => 'local',
            'size' => 4,
            'status' => 'completed',
            'trigger' => 'manual',
            'driver' => 'mysql',
            'method' => 'mysqldump',
            'duration_ms' => 1,
        ]);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->firstOrFail()->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);
    }
}
