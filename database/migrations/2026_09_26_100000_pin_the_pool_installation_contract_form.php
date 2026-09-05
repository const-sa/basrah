<?php

use App\Support\PoolInstallationContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The pools sell piping and installation on their own printed pad, and an
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
            ->where('name', PoolInstallationContractTemplate::NAME)
            ->exists();

        // An install that already has the form keeps its own copy: the text is
        // editable from «قوالب العقود», and this must not overwrite an edit.
        if ($exists) {
            return;
        }

        DB::table('contract_templates')->insert([
            'name' => PoolInstallationContractTemplate::NAME,
            ...PoolInstallationContractTemplate::attributes(),
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
            ->where('name', PoolInstallationContractTemplate::NAME)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('contracts')
                ->whereColumn('contracts.contract_template_id', 'contract_templates.id'))
            ->delete();
    }
};
