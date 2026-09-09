<?php

use App\Support\SystemRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Carry existing groups onto the keys the matrix now offers, and take back the
 * one key that granted nothing yesterday and voids invoices today.
 */
return new class extends Migration
{
    /** Keys derived from a key the group already holds. */
    private const DERIVED = [
        // Purchases were guarded by two keys the registry never offered, so
        // whoever could raise an invoice gets the edit and delete meant with it.
        'purchases.create' => ['purchases.edit', 'purchases.delete'],
        // The units screen rode on the items keys, so it travels with them.
        'items.view' => ['measure_units.view'],
        'items.create' => ['measure_units.create'],
        'items.edit' => ['measure_units.edit'],
        'items.delete' => ['measure_units.delete'],
        // Export is reading by another name — whoever reads the register exports it.
        'sales.view' => ['sales.export'],
    ];

    public function up(): void
    {
        DB::table('roles')->orderBy('id')->each(function ($role) {
            $permissions = json_decode($role->permissions ?? '[]', true);

            if (! is_array($permissions)) {
                return;
            }

            if ($role->slug === 'super-admin') {
                $granted = SystemRegistry::permissionKeys();
            } else {
                $granted = $permissions;

                foreach (self::DERIVED as $source => $targets) {
                    if (in_array($source, $permissions, true)) {
                        $granted = [...$granted, ...$targets];
                    }
                }

                // Voiding an invoice was a box that granted nothing, so holding
                // it was never a grant. It is given deliberately now, not inherited.
                $granted = array_filter($granted, fn ($key) => $key !== 'sales.delete');

                $granted = array_values(array_unique($granted));
            }

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($granted, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    /** Strips only the keys added above; sales.delete is not handed back. */
    public function down(): void
    {
        $added = array_merge(...array_values(self::DERIVED));

        DB::table('roles')->orderBy('id')->each(function ($role) use ($added) {
            $permissions = json_decode($role->permissions ?? '[]', true);

            if (! is_array($permissions)) {
                return;
            }

            $kept = array_values(array_filter(
                $permissions,
                fn ($key) => ! in_array($key, $added, true),
            ));

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($kept, JSON_UNESCAPED_UNICODE),
            ]);
        });
    }
};
