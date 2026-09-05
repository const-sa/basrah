<?php

namespace App\Support;

/**
 * The activity a client belongs to — the divider that keeps the three
 * registers apart. Its keys are the unit types, so a booking names its own.
 */
class ClientType
{
    public const POOL = 'pool';

    public const CHALET = 'chalet';

    public const HALL = 'hall';

    /** The pools sell to whoever walks in, so an unmarked client is theirs. */
    public const DEFAULT = self::POOL;

    /** Department code → the registers that department sells to. */
    private const BY_DEPARTMENT = [
        'POOLS' => [self::POOL],
        'VENUES' => [self::HALL, self::CHALET],
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            self::POOL => 'المسابح',
            self::HALL => 'القاعات',
            self::CHALET => 'الشاليهات',
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function label(?string $type): string
    {
        return self::all()[$type] ?? '—';
    }

    /** Anything unknown falls back rather than dropping out of every list. */
    public static function normalize(?string $type): string
    {
        return in_array($type, self::keys(), true) ? $type : self::DEFAULT;
    }

    /**
     * Null for a department that sells to nobody in particular (ADMIN).
     *
     * @return list<string>|null
     */
    public static function forDepartmentCode(?string $code): ?array
    {
        return self::BY_DEPARTMENT[strtoupper((string) $code)] ?? null;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function forFrontend(): array
    {
        return collect(self::all())
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }
}
