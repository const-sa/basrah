<?php

namespace App\Support;

use App\Models\CostCenter;

/**
 * The activity a cost centre belongs to — halls, chalets, pools, or none.
 * A unit's centre carries its type; the pools have no unit, only a department.
 */
class ActivitySegment
{
    public const HALLS = 'halls';

    public const CHALETS = 'chalets';

    public const POOLS = 'pools';

    public const OTHER = 'other';

    /** The activities as the operator sees them, not as the ledger does. */
    public const ALL = [
        self::HALLS => 'القاعات',
        self::CHALETS => 'الشاليهات',
        self::POOLS => 'المسابح',
        self::OTHER => 'أخرى',
    ];

    /** The pools department's code — its centre carries that activity's money. */
    public const POOLS_DEPARTMENT = 'POOLS';

    /**
     * Centre id → its activity. Read once and kept for the request.
     *
     * @var array<int, string>|null
     */
    private ?array $map = null;

    /**
     * The three a screen can be pinned to — «other» is where the rest falls.
     *
     * @return list<string>
     */
    public static function activities(): array
    {
        return [self::HALLS, self::CHALETS, self::POOLS];
    }

    public static function isActivity(?string $segment): bool
    {
        return in_array($segment, self::activities(), true);
    }

    public static function label(?string $segment): string
    {
        return self::ALL[$segment] ?? '—';
    }

    /**
     * @return array<int, string>
     */
    public function map(): array
    {
        return $this->map ??= CostCenter::with(['unit:id,type', 'section:id,unit_id', 'section.unit:id,type', 'department:id,code'])
            ->get()
            ->mapWithKeys(function (CostCenter $c) {
                // A room belongs to its unit's activity: read through the
                // section, or it falls into «أخرى» and off that total.
                $type = $c->unit?->type ?? $c->section?->unit?->type;

                return [$c->id => match (true) {
                    $type === 'hall' => self::HALLS,
                    $type === 'chalet' => self::CHALETS,
                    $c->department?->code === self::POOLS_DEPARTMENT => self::POOLS,
                    default => self::OTHER,
                }];
            })
            ->all();
    }

    public function of(?int $costCenterId): string
    {
        return $this->map()[$costCenterId] ?? self::OTHER;
    }

    /**
     * @return list<int>
     */
    public function centerIds(string $segment): array
    {
        return array_values(array_keys(array_filter($this->map(), fn (string $s) => $s === $segment)));
    }

    /**
     * What a centre is called on screen: the unit, then the section behind its
     * unit's name, then the department. The stored name is the last resort.
     */
    public function nameOf(CostCenter $center): ?string
    {
        return self::nameFrom(
            $center->unit?->name,
            $center->section?->unit?->name,
            $center->section?->name,
            $center->department?->name,
            $center->name,
        );
    }

    /**
     * The same naming for a row read by a join, which carries no model.
     */
    public static function nameFrom(
        ?string $unit,
        ?string $sectionUnit,
        ?string $section,
        ?string $department,
        ?string $center,
    ): ?string {
        if ($unit !== null) {
            return $unit;
        }

        if ($section !== null) {
            return $sectionUnit !== null ? $sectionUnit.' — '.$section : $section;
        }

        return $department ?? $center;
    }
}
