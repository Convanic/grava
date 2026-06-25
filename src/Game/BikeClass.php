<?php
declare(strict_types=1);

namespace App\Game;

/**
 * Fahrrad-Klasse für Segment-Rekorde (GPX `<ge:bikeType>`).
 *
 * Speicherung granular (bike/gravel/road/mtb/ebike/other + Legacy muscle).
 * API-Filter `/records?bike=` und Crowns nach Motor-Gruppe (GAME_BIKE_CLASS_UPDATE.md).
 */
final class BikeClass
{
    public const BIKE  = 'bike';
    public const GRAVEL = 'gravel';
    public const ROAD  = 'road';
    public const MTB   = 'mtb';
    public const EBIKE = 'ebike';
    public const OTHER = 'other';
    /** Legacy-Speicherwert (Testdaten/Alt-Ingest) — zählt zur Muskel-Gruppe. */
    public const LEGACY_MUSCLE = 'muscle';

    public const ALL = 'all';
    /** Query-Parameter: alle Nicht-E-Bike-Pässe (Filter, kein Speicherwert). */
    public const MUSCLE = 'muscle';

    /** @var list<string> */
    public const STORAGE = [
        self::BIKE, self::GRAVEL, self::ROAD, self::MTB, self::EBIKE, self::OTHER, self::LEGACY_MUSCLE,
    ];

    /** @var list<string> */
    public const MOTOR_GROUPS = [self::MUSCLE, self::EBIKE];

    public static function normalize(?string $raw): string
    {
        $v = strtolower(trim((string)$raw));
        return in_array($v, self::STORAGE, true) ? $v : self::OTHER;
    }

    /** Normalisiert den `bike`-Query-Parameter auf muscle|ebike|all. */
    public static function parseQuery(?string $bike): string
    {
        $v = strtolower(trim((string)$bike));
        if ($v === self::ALL) {
            return self::ALL;
        }
        if ($v === self::EBIKE) {
            return self::EBIKE;
        }
        return self::MUSCLE;
    }

    public static function isEbike(?string $storedClass): bool
    {
        return $storedClass === self::EBIKE;
    }

    /** @return self::MUSCLE|self::EBIKE */
    public static function motorGroup(?string $storedClass): string
    {
        return self::isEbike($storedClass) ? self::EBIKE : self::MUSCLE;
    }

    /**
     * SQL-Suffix für Motor-Gruppen-Filter auf `bike_class`.
     *
     * @return array{0:string,1:list<string>}|null null = kein Filter (all)
     */
    public static function sqlMotorFilter(string $queryBike): ?array
    {
        if ($queryBike === self::ALL) {
            return null;
        }
        if ($queryBike === self::EBIKE) {
            return [' AND bike_class = ?', [self::EBIKE]];
        }
        return [' AND (bike_class IS NULL OR bike_class <> ?)', [self::EBIKE]];
    }
}
