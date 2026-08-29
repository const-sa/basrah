<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The trial ("demo") logins are cleared out of every install.
 *
 * They were useful while the system was being shown around, but they are shared
 * accounts on one published password — one of them a super-admin — and leaving
 * them standing anywhere real is an open door. The panel on the employees page
 * stays put, so anyone who needs a demo login again generates one deliberately
 * rather than finding one already waiting.
 *
 * An account is removed outright wherever the schema lets it go. Almost every
 * column pointing at a user is `on delete set null`, so audit entries and any
 * bookings a demo user happened to create survive the removal — they just stop
 * naming a creator. The exceptions are `purchases.user_id` and
 * `quotations.user_id`, which are `not null` and refuse the delete; an account
 * pinned by one of those is retired instead — deactivated, stripped of its unit
 * access and soft-deleted — which shuts the login just as firmly while the
 * purchase keeps its buyer.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Queried raw so accounts already soft-deleted are swept up too.
        $ids = DB::table('users')->where('is_demo', true)->pluck('id');

        foreach ($ids as $id) {
            $pinned = DB::table('purchases')->where('user_id', $id)->exists()
                || DB::table('quotations')->where('user_id', $id)->exists();

            DB::table('unit_user')->where('user_id', $id)->delete();

            if ($pinned) {
                DB::table('users')->where('id', $id)->update([
                    'is_active' => false,
                    'has_all_units' => false,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('users')->where('id', $id)->delete();
        }
    }

    public function down(): void
    {
        // Nothing to put back. Recreating four accounts on a password printed in
        // the source is the state this exists to end, and the employees page can
        // mint them again on request, which is the point of asking first.
    }
};
