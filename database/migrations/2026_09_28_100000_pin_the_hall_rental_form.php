<?php

use App\Support\HallRentalContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The halls are let on their own numbered pad, and an install already running
 * has no way to reach it: the seeder pins the forms once, at setup.
 *
 * Written here rather than as a seeder rerun so no other template is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('contract_templates')
            ->where('name', HallRentalContractTemplate::NAME)
            ->exists();

        // An install that already has the pad keeps its own copy: the text is
        // editable from «قوالب العقود», and this must not overwrite an edit.
        if ($exists) {
            return;
        }

        DB::table('contract_templates')->insert([
            'name' => HallRentalContractTemplate::NAME,
            ...HallRentalContractTemplate::attributes(),
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
            ->where('name', HallRentalContractTemplate::NAME)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('contracts')
                ->whereColumn('contracts.contract_template_id', 'contract_templates.id'))
            ->delete();
    }
};
