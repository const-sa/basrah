<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hand notifications.view to every existing group.
 *
 * The key existed before the inbox did, and only the owner's group held it —
 * so the day the system starts announcing its own work, every other group
 * would be told nothing, and no one would know a key was missing.
 *
 * The inbox is each person's own screen, like the dashboard: it opens nothing
 * that its holder could not already open, because a notice is only ever sent
 * to someone holding the register it speaks of.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(fn (array $permissions) => array_values(array_unique([
            ...$permissions,
            'notifications.view',
        ])));
    }

    /**
     * Only the key this migration added is taken back.
     */
    public function down(): void
    {
        $this->rewrite(fn (array $permissions) => array_values(array_filter(
            $permissions,
            fn ($key) => $key !== 'notifications.view',
        )));
    }

    private function rewrite(callable $change): void
    {
        DB::table('roles')->orderBy('id')->each(function ($role) use ($change) {
            $permissions = json_decode($role->permissions ?? '[]', true);

            if (! is_array($permissions)) {
                return;
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($change($permissions), JSON_UNESCAPED_UNICODE),
            ]);
        });
    }
};
