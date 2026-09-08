<?php

use App\Support\HallServicesContractTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The seeder pins the forms once, at setup, so an install already running has
 * no way to reach the halls' services list — this puts it there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('contract_templates')
            ->where('name', HallServicesContractTemplate::NAME)
            ->exists();

        // An install that already has the sheet keeps its own copy: the text is
        // editable from «قوالب العقود», and this must not overwrite an edit.
        if ($exists) {
            return;
        }

        DB::table('contract_templates')->insert([
            'name' => HallServicesContractTemplate::NAME,
            ...HallServicesContractTemplate::attributes(),
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
            ->where('name', HallServicesContractTemplate::NAME)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('contracts')
                ->whereColumn('contracts.contract_template_id', 'contract_templates.id'))
            ->delete();
    }
};
