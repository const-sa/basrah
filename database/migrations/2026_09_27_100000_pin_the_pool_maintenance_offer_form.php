<?php

use App\Support\PoolMaintenanceContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The pools offer monthly maintenance on their own printed sheet, and an
 * install already running has no way to reach it: the seeder pins the forms
 * once, at setup, so a template added afterwards would never appear.
 *
 * Written here rather than as a seeder rerun so no other template is touched —
 * rerunning the seeder rewrites the standard form over whatever the admin has
 * edited into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('contract_templates')
            ->where('name', PoolMaintenanceContractTemplate::NAME)
            ->exists();

        // An install that already has the sheet keeps its own copy: the text is
        // editable from «قوالب العقود», and this must not overwrite an edit.
        if ($exists) {
            return;
        }

        DB::table('contract_templates')->insert([
            'name' => PoolMaintenanceContractTemplate::NAME,
            ...PoolMaintenanceContractTemplate::attributes(),
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Only while no contract was drawn on it — a contract keeps its own
        // frozen text, but dropping the row it points to loses the form itself.
        DB::table('contract_templates')
            ->where('name', PoolMaintenanceContractTemplate::NAME)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('contracts')
                ->whereColumn('contracts.contract_template_id', 'contract_templates.id'))
            ->delete();
    }
};
