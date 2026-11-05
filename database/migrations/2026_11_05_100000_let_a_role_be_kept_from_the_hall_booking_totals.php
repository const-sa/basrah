<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * «عرض الإجماليات» on the hall bookings list.
 *
 * The footer sums of the list — total, paid and remaining across the filter —
 * were shown to anyone who could open it. The owner wants the employee who
 * only records bookings kept from them, so they now sit behind their own key.
 *
 * Every role that sees the list today is granted the key, so nobody loses
 * anything on the day this runs; the owner then takes it from the one role
 * that should not have it, on the groups screen.
 */
return new class extends Migration
{
    private const VIEWERS = 'hall_bookings.view';

    private const GRANTED = 'hall_bookings.totals';

    public function up(): void
    {
        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions)
                || ! in_array(self::VIEWERS, $permissions, true)
                || in_array(self::GRANTED, $permissions, true)) {
                continue;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode([...$permissions, self::GRANTED], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    /** Not taken back: it cannot tell a grant from here from one made by hand since. */
    public function down(): void {}
};
