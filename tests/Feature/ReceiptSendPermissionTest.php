<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * من يقبض الدفعة يرسل سندها: مفتاح الإرسال يلحق كل دورٍ يعدّل الحجوزات،
 * ولا يلحق دوراً لا يعدّلها.
 */
class ReceiptSendPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_seeded_unit_supervisor_can_send_a_receipt(): void
    {
        $this->seed(RolesSeeder::class);

        $supervisor = Role::where('slug', 'unit-supervisor')->firstOrFail();

        $this->assertTrue($supervisor->hasPermission('whatsapp.send'));
        $this->assertTrue($supervisor->hasPermission('hall_bookings.edit'));
    }

    public function test_an_older_role_that_edits_bookings_is_granted_the_key(): void
    {
        // دورٌ أُنشئ قبل أن تُضاف whatsapp.send، فبقي يعدّل الحجوزات بلا إرسال.
        $id = DB::table('roles')->insertGetId([
            'name' => 'مشرف قاعات قديم',
            'slug' => 'legacy-hall-supervisor',
            'permissions' => json_encode(['hall_bookings.view', 'hall_bookings.edit'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cashier = DB::table('roles')->insertGetId([
            'name' => 'كاشير قديم',
            'slug' => 'legacy-cashier',
            'permissions' => json_encode(['pos.view', 'sales.create'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runReceiptMigration();

        $this->assertTrue(Role::find($id)->hasPermission('whatsapp.send'));
        // من لا يعدّل الحجوزات لا يقبض دفعاتها، فلا يُمنح شيئاً.
        $this->assertFalse(Role::find($cashier)->hasPermission('whatsapp.send'));
    }

    private function runReceiptMigration(): void
    {
        $path = database_path('migrations/2026_09_28_100000_let_whoever_takes_the_payment_send_its_receipt.php');

        (require $path)->up();
    }
}
