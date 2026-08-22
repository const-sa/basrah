<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Role;
use App\Models\User;
use App\Services\BackupService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * تنزيل قاعدة البيانات ورفعها واستعادتها (§18).
 *
 * الاستعادة هي الفعل الذي لا يُراجَع في هذه الشاشة، فالمحروس فيها ثلاثة:
 * أن تكتب فعلًا ما في الملف، وأن تترك نسخة أمانٍ قبل أن تكتب، وألّا تُفتح
 * لمن يملك الرفع وحده.
 */
class BackupRestoreTest extends TestCase
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
        $directory = storage_path('app/'.config('operations.backup.path', 'backups'));

        if (File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    /**
     * التنزيل المباشر: نسخةٌ تُؤخذ الآن ويصل ملفها في الرد نفسه.
     */
    public function test_the_database_is_downloaded_in_one_click(): void
    {
        $response = $this->actingAs($this->owner)->get('/admin/backups/export');

        $response->assertOk();
        $response->assertDownload();

        // والنسخة تُسجَّل كغيرها لأنها ملفٌ حقيقي على القرص.
        $backup = Backup::completed()->firstOrFail();
        $this->assertTrue(File::exists(app(BackupService::class)->path($backup->filename)));
    }

    /**
     * الرفع بلا استعادة يحفظ الملف ولا يمسّ القاعدة.
     */
    public function test_an_uploaded_file_is_stored_without_touching_the_database(): void
    {
        $before = User::count();

        $this->actingAs($this->owner)
            ->post('/admin/backups/upload', [
                'file' => UploadedFile::fake()->createWithContent('dump.sql', "-- نسخة\nSELECT 1;\n"),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $backup = Backup::where('trigger', 'upload')->firstOrFail();

        $this->assertSame('completed', $backup->status);
        // الاسم الأصلي لا يصل القرص — يُولَّد اسمٌ ويُستبقى الامتداد وحده.
        $this->assertStringStartsWith('upload-', $backup->filename);
        $this->assertStringEndsWith('.sql', $backup->filename);
        $this->assertTrue(File::exists(app(BackupService::class)->path($backup->filename)));

        $this->assertSame($before, User::count());
    }

    /**
     * الاستعادة تُعيد القاعدة إلى حال النسخة: ما حدث بعدها يزول.
     */
    public function test_restoring_returns_the_database_to_the_state_of_the_backup(): void
    {
        User::factory()->create(['name' => 'موجود قبل النسخة']);

        $backup = app(BackupService::class)->run('manual', $this->owner->id);

        User::factory()->create(['name' => 'أُضيف بعد النسخة']);
        $this->assertDatabaseHas('users', ['name' => 'أُضيف بعد النسخة']);

        $this->actingAs($this->owner)
            ->post("/admin/backups/{$backup->id}/restore")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['name' => 'موجود قبل النسخة']);
        $this->assertDatabaseMissing('users', ['name' => 'أُضيف بعد النسخة']);

        // ولا تتضاعف الصفوف: الاستعادة استبدالٌ لا إضافة فوق القائم.
        $this->assertSame(1, User::where('name', 'موجود قبل النسخة')->count());
    }

    /**
     * نسخة الأمان تُؤخذ قبل الاستعادة لا بعدها — وإلا فلا مرجع لمن أخطأ الملف.
     *
     * وتبقى ظاهرة في السجل بعد الاستعادة: سجل النسخ نفسه جزءٌ ممّا يُستبدل،
     * فلولا إعادةُ صفّها لصار ملف الرجوع موجودًا على القرص لا يراه أحد في
     * الشاشة — وهو أسوأ من ألّا يوجد، لأن المستخدم يظنّ أنه بلا مخرج.
     */
    public function test_a_safety_backup_is_taken_before_restoring_and_stays_listed(): void
    {
        User::factory()->create(['name' => 'يزول بالاستعادة']);

        $backup = app(BackupService::class)->run('manual', $this->owner->id);

        $this->actingAs($this->owner)->post("/admin/backups/{$backup->id}/restore")->assertRedirect();

        $safety = Backup::where('trigger', 'pre_restore')->firstOrFail();

        $this->assertSame('completed', $safety->status);
        $this->assertTrue(File::exists(app(BackupService::class)->path($safety->filename)));

        // والنسخة المستعادة نفسها تبقى مدرجة كي تُستعاد ثانيةً إن لزم.
        $this->assertDatabaseHas('backups', ['filename' => $backup->filename]);
    }

    /**
     * الرجوع عن استعادةٍ خاطئة: نسخة الأمان تُستعاد بدورها فيعود ما زال.
     */
    public function test_the_safety_backup_can_be_restored_to_undo_the_restore(): void
    {
        $service = app(BackupService::class);

        // نسخةٌ قديمة لا يعرف عنها «الموظف الجديد» شيئًا.
        $old = $service->run('manual', $this->owner->id);

        User::factory()->create(['name' => 'الموظف الجديد']);

        // استعادةٌ متسرّعة تمحوه…
        $this->actingAs($this->owner)->post("/admin/backups/{$old->id}/restore")->assertRedirect();
        $this->assertDatabaseMissing('users', ['name' => 'الموظف الجديد']);

        // …ونسخة الأمان تُعيده.
        $safety = Backup::where('trigger', 'pre_restore')->firstOrFail();

        $this->post("/admin/backups/{$safety->id}/restore")->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['name' => 'الموظف الجديد']);
    }

    /**
     * الرفع والاستعادة في خطوة واحدة حين يُطلب ذلك صراحةً.
     */
    public function test_uploading_with_the_restore_flag_applies_the_file(): void
    {
        $service = app(BackupService::class);
        $backup = $service->run('manual', $this->owner->id);
        $path = $service->path($backup->filename);

        User::factory()->create(['name' => 'أُضيف بعد النسخة']);

        $this->actingAs($this->owner)
            ->post('/admin/backups/upload', [
                'file' => new UploadedFile($path, basename($path), null, null, true),
                'restore' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['name' => 'أُضيف بعد النسخة']);
    }

    /**
     * ما ليس ملف نسخة لا يُقبل — والامتداد أول بابٍ يُغلق.
     */
    public function test_a_file_that_is_not_a_backup_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post('/admin/backups/upload', [
                'file' => UploadedFile::fake()->create('malware.php', 4),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, Backup::count());
    }

    /**
     * الاستعادة صلاحيةٌ مستقلة: من يأخذ النسخ ويرفعها لا يكتب بها فوق القاعدة.
     */
    public function test_restoring_needs_its_own_permission(): void
    {
        $backup = app(BackupService::class)->run('manual', $this->owner->id);

        $keeper = User::factory()->create([
            'role_id' => Role::create([
                'name' => 'حافظ النسخ',
                'slug' => 'backup-keeper',
                'permissions' => ['backups.view', 'backups.create'],
            ])->id,
            'is_active' => true,
            'has_all_units' => true,
        ]);

        $this->actingAs($keeper)->post("/admin/backups/{$backup->id}/restore")->assertForbidden();

        // ويرفع الملف، لكن خانة «استعادة فور الرفع» لا تنفذ له.
        $this->post('/admin/backups/upload', [
            'file' => UploadedFile::fake()->createWithContent('dump.sql', "SELECT 1;\n"),
            'restore' => true,
        ])->assertSessionHas('error');

        $this->assertSame(0, Backup::where('trigger', 'pre_restore')->count());
        $this->assertSame(1, Backup::where('trigger', 'upload')->count());
    }
}
