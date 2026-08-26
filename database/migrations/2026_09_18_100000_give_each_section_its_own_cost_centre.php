<?php

use App\Models\CostCenter;
use App\Models\UnitSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cost centre for each section of a unit.
 *
 * Revenue has always been posted to the unit's centre, so a chalet let by the
 * room reported one figure however many rooms it holds and whichever of them
 * earned it. The revenues screen is built entirely on cost centres — its «من
 * أيّ وحدة؟» card and its unit filter both read them — so giving a room a
 * centre of its own is what puts the room on that screen, with no new idea
 * introduced anywhere.
 *
 * Every existing section gets one now rather than on first use: a room that has
 * earned nothing yet should still be selectable, answering «لا شيء» instead of
 * being missing from the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cost_centers', 'unit_section_id')) {
            Schema::table('cost_centers', function (Blueprint $table) {
                $table->foreignId('unit_section_id')->nullable()->after('unit_id')
                    ->constrained('unit_sections')->nullOnDelete();
            });
        }

        UnitSection::with('unit')->get()->each(fn (UnitSection $section) => CostCenter::forSection($section));
    }

    public function down(): void
    {
        // The centres go with the column: a line pointing at one is re-attributed
        // by the migration that split it, not by this one.
        CostCenter::whereNotNull('unit_section_id')->delete();

        if (Schema::hasColumn('cost_centers', 'unit_section_id')) {
            Schema::table('cost_centers', function (Blueprint $table) {
                $table->dropForeign(['unit_section_id']);
                $table->dropColumn('unit_section_id');
            });
        }
    }
};
