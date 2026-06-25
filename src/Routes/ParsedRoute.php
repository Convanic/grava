<?php
declare(strict_types=1);

namespace App\Routes;

use DateTimeImmutable;

/**
 * Ergebnis eines erfolgreichen Parser-Laufs (GPX oder GeoJSON).
 *
 * Eine einzige Folge von {@see ParsedPoint}s. Falls die Eingabe mehrere
 * Tracks oder Segmente hatte, hat der Parser diese in ihrer
 * natürlichen Reihenfolge zusammengeflattened — der GeometryStats-
 * Schritt rechnet anschließend Distanz/Höhenmeter über die gesamte
 * Folge. Mehrteilige Routen sind damit aktuell ein flacher Track;
 * eine spätere Erweiterung könnte Tracks separat ausweisen, ohne
 * an dieser Datenstruktur etwas zu ändern.
 */
final class ParsedRoute
{
    /**
     * @param list<ParsedPoint>      $points
     * @param 'gpx'|'geojson'        $sourceFormat
     * @param ?float                 $elevationGainOverrideM Exakter Gesamtanstieg
     *        aus einer `<ge:elevationGain>`-Extension (z. B. barometrisch vom
     *        iOS-Client). Wenn gesetzt, bevorzugt {@see GeometryStats} ihn
     *        gegenüber dem aus `<ele>` berechneten Wert.
     * @param string                 $bikeClass Normalisierte Klasse aus `<ge:bikeType>`
     *        (fehlt → `other`).
     * @param bool                   $hasRecordingMarkers true wenn mindestens ein
     *        `<ge:surfaceScore>` an einem Trackpunkt (echte App-Aufzeichnung).
     */
    public function __construct(
        public readonly array $points,
        public readonly string $sourceFormat,
        public readonly ?DateTimeImmutable $startedAt = null,
        public readonly ?DateTimeImmutable $endedAt = null,
        public readonly ?float $elevationGainOverrideM = null,
        public readonly string $bikeClass = 'other',
        public readonly bool $hasRecordingMarkers = false,
    ) {
    }

    public function pointCount(): int
    {
        return count($this->points);
    }
}
