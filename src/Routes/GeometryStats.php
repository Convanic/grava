<?php
declare(strict_types=1);

namespace App\Routes;

/**
 * Berechnet aus einer {@see ParsedRoute} die in der DB persistierten
 * Aggregat-Werte: Distanz, Höhenmeter, BBox, Centroid, Punkteanzahl,
 * Start-/End-Zeitstempel.
 *
 * Bewusst keine Library-Anbindung — die phpgpx-eigenen Stats wären
 * nur für GPX verfügbar und würden zwischen GPX und GeoJSON zu
 * abweichenden Ergebnissen führen. Eine eigene, deterministische
 * Implementierung sorgt dafür, dass „dieselbe Route in beiden
 * Formaten" exakt dieselben Stats liefert.
 *
 * **Distanz:** Haversine-Formel. Annahme einer kugelförmigen Erde mit
 * mittlerem Radius 6371 km — Fehler < 0.5 % gegenüber WGS84-Ellipsoid,
 * ausreichend für den Use Case (Routen-Längen-Anzeige in der App).
 *
 * **Höhenmeter:** Sumiert positive Elevation-Differenzen mit einer
 * Hysterese von {@see self::ELEVATION_HYSTERESIS_M} Metern. Ohne
 * Hysterese würde die typische GPS-Höhen-Sägezahn-Linie
 * Hunderte von Metern an "Phantom-Höhenmetern" produzieren.
 */
final class GeometryStats
{
    /**
     * Mittlerer Erdradius in Metern, wie für Haversine üblich.
     */
    private const EARTH_RADIUS_M = 6_371_000.0;

    /**
     * Default-Hysterese in Metern, falls keine Konfiguration übergeben wird.
     * GPS-`<ele>` rauscht stärker als ein Barometer (typ. 1–3 m pro Sample),
     * daher 3 m als gutartiger Default; via Konstruktor (Config
     * `ROUTE_ELEVATION_THRESHOLD_M`) nachtunbar.
     */
    public const DEFAULT_ELEVATION_HYSTERESIS_M = 3.0;

    /**
     * Hysterese in Metern: Höhenänderungen unterhalb dieses Wertes gelten als
     * Rauschen und zählen nicht in den Höhenmeter-Counter.
     */
    private readonly float $elevationHysteresisM;

    public function __construct(?float $elevationHysteresisM = null)
    {
        $v = $elevationHysteresisM ?? self::DEFAULT_ELEVATION_HYSTERESIS_M;
        // Negative/0 wären sinnlos (jede Schwankung zählte) → auf Default fallen.
        $this->elevationHysteresisM = $v > 0.0 ? $v : self::DEFAULT_ELEVATION_HYSTERESIS_M;
    }

    public function compute(ParsedRoute $route): RouteStats
    {
        $points = $route->points;
        $n = count($points);
        if ($n < 2) {
            // Sollte vom Parser abgefangen sein. Als Defense-in-Depth
            // werfen wir hier nochmal — sonst hätten wir Division durch
            // null beim Centroid-Mittelwert.
            throw new \LogicException('GeometryStats erwartet mindestens 2 Punkte.');
        }

        $distanceM       = 0.0;
        $elevationGainM  = 0.0;
        $minLat = $maxLat = $points[0]->lat;
        $minLon = $maxLon = $points[0]->lon;
        $sumLat = 0.0;
        $sumLon = 0.0;

        // Höhenmeter mit Hysterese: wir tracken die letzte „bestätigte"
        // Höhe und addieren die Differenz nur, wenn sie die Schwelle
        // überschreitet.
        $referenceElevation = $points[0]->elevationM;

        for ($i = 0; $i < $n; $i++) {
            $p = $points[$i];

            $sumLat += $p->lat;
            $sumLon += $p->lon;

            if ($p->lat < $minLat) { $minLat = $p->lat; }
            if ($p->lat > $maxLat) { $maxLat = $p->lat; }
            if ($p->lon < $minLon) { $minLon = $p->lon; }
            if ($p->lon > $maxLon) { $maxLon = $p->lon; }

            if ($i > 0) {
                $distanceM += self::haversine(
                    $points[$i - 1]->lat, $points[$i - 1]->lon,
                    $p->lat, $p->lon,
                );

                if ($referenceElevation !== null && $p->elevationM !== null) {
                    $delta = $p->elevationM - $referenceElevation;
                    if (abs($delta) >= $this->elevationHysteresisM) {
                        if ($delta > 0) {
                            $elevationGainM += $delta;
                        }
                        // Reference auf den neuen "bestätigten" Wert
                        // hochziehen, egal ob der Step aufwärts oder
                        // abwärts ging.
                        $referenceElevation = $p->elevationM;
                    }
                } elseif ($referenceElevation === null && $p->elevationM !== null) {
                    // Erste verfügbare Höhe wird Referenz.
                    $referenceElevation = $p->elevationM;
                }
            }
        }

        $centroidLat = $sumLat / $n;
        $centroidLon = $sumLon / $n;

        // §3: exakten Wert aus <ge:elevationGain> bevorzugen, falls vorhanden;
        // sonst den aus <ele> per Hysterese berechneten Anstieg verwenden.
        $gain = $route->elevationGainOverrideM !== null
            ? $route->elevationGainOverrideM
            : $elevationGainM;

        return new RouteStats(
            pointCount: $n,
            distanceM: (int)round($distanceM),
            elevationGainM: (int)round($gain),
            bboxMinLat: $minLat,
            bboxMinLon: $minLon,
            bboxMaxLat: $maxLat,
            bboxMaxLon: $maxLon,
            centroidLat: $centroidLat,
            centroidLon: $centroidLon,
            startedAt: $route->startedAt,
            endedAt: $route->endedAt,
        );
    }

    /**
     * Haversine-Distanz zwischen zwei Lat/Lon-Punkten in Metern.
     *
     * Public-static, damit andere Stellen (z. B. Spatial-Verifikation
     * im Test, Tools) die gleiche Formel ohne Stats-Overhead nutzen
     * können.
     */
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLam = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2) ** 2
           + cos($phi1) * cos($phi2) * sin($dLam / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_M * $c;
    }
}
