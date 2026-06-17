<?php
declare(strict_types=1);

namespace App\Routes;

use DateTimeImmutable;

/**
 * Ein einzelner Track-Punkt nach dem Parsen aus GPX oder GeoJSON.
 *
 * Bewusst flach gehalten: alle Felder sind die Werte, die auch der
 * GeometryStats-Schritt direkt braucht. Keine Vererbung von
 * Library-Typen, damit der Rest der Codebasis nichts über phpgpx
 * wissen muss.
 */
final class ParsedPoint
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lon,
        public readonly ?float $elevationM,
        public readonly ?DateTimeImmutable $timestamp,
    ) {
    }
}
