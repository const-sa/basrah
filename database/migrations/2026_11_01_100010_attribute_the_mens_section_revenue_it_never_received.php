<?php

use App\Support\Accounting\SectionRevenueAttribution;
use Illuminate\Database\Migrations\Migration;

/**
 * Re-attribute the revenue of a room that was credited to the room beside it.
 *
 * The revenue of a unit let by the room was apportioned by the price each room
 * was let at, and a room the price list had no row for came out at a zero
 * share. A zero line is never written, so that room received nothing at all
 * while the room after it — always the last, always the one taking the
 * remainder — was credited the whole booking. «قسم الرجال» is seeded first on
 * every unit, so it was always the one that disappeared.
 *
 * The splitter now shares evenly where any room is unpriced. This re-runs it
 * over what was already posted.
 *
 * Idempotent: an entry whose revenue is already split carries more than one
 * credit line and is skipped, and a single room already sitting on its own
 * centre is left alone. No entry gains or loses a riyal — one line is replaced
 * by lines summing to the same amount on the same account, inside one
 * transaction — so no balance moves, only which centre carries the figure.
 *
 * Must run before any revenue is recognized for the first time: entries fixed
 * here then match what reviseRevenue() expects, so it stays a no-op instead of
 * reversing and reposting hundreds of entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(SectionRevenueAttribution::class)->apply();
    }

    public function down(): void
    {
        // Nothing to undo: the money never moved, only the centre it is read
        // on, and the reading it had before was wrong.
    }
};
