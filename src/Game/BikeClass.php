<?php
declare(strict_types=1);

namespace App\Game;

/** Fahrrad-Klasse für Segment-Rekorde (GPX `<ge:bikeType>`). */
final class BikeClass
{
    public const MUSCLE = 'muscle';
    public const EBIKE  = 'ebike';
    public const ROAD   = 'road';
    public const OTHER  = 'other';
    public const ALL    = 'all';

    /** @var list<string> */
    public const ALLOWED = [self::MUSCLE, self::EBIKE, self::ROAD, self::OTHER];

    public static function normalize(?string $raw): string
    {
        $v = strtolower(trim((string)$raw));
        return in_array($v, self::ALLOWED, true) ? $v : self::OTHER;
    }

    public static function parseQuery(?string $bike): string
    {
        $v = strtolower(trim((string)$bike));
        if ($v === '' || $v === self::MUSCLE) {
            return self::MUSCLE;
        }
        if ($v === self::ALL) {
            return self::ALL;
        }
        return in_array($v, self::ALLOWED, true) ? $v : self::MUSCLE;
    }
}
