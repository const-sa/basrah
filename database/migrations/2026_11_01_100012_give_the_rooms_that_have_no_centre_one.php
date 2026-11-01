<?php

use App\Models\CostCenter;
use App\Models\UnitSection;
use Illuminate\Database\Migrations\Migration;

/**
 * Give every room a cost centre, whether it has earned anything or not.
 *
 * A room's centre was only ever created the moment something posted on it, so
 * a room with no revenue yet existed nowhere: «قسم الرجال» of a hall whose two
 * bookings are still ahead of their date was simply absent from the report,
 * and the operator reading it could not tell an empty room from a missing one.
 * Twenty of the twenty-three rooms on this system had no centre at all.
 *
 * Idempotent: forSection() is a firstOrCreate on the room, so a room that
 * already has one is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        UnitSection::with('unit')->chunkById(200, function ($sections) {
            foreach ($sections as $section) {
                CostCenter::forSection($section);
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: an empty centre carries no entries, and deleting
        // one a posting has since used would orphan its lines.
    }
};
