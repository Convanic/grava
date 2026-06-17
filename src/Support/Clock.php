<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    public static function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function nowUtcString(): string
    {
        return self::nowUtc()->format('Y-m-d H:i:s');
    }

    public static function utcPlusSeconds(int $seconds): string
    {
        return self::nowUtc()->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    public static function toIso8601(string $mysqlDatetime): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $mysqlDatetime, new DateTimeZone('UTC'));
        if ($dt === false) return $mysqlDatetime;
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
}
