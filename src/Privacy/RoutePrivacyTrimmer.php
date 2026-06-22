<?php
declare(strict_types=1);

namespace App\Privacy;

/**
 * Trimmt eine GeoJSON-FeatureCollection (aus {@see \App\Routes\RouteGeoJson})
 * für die Auslieferung an FREMDE: Track-Punkte innerhalb der Zone des
 * Routen-Eigentümers werden entfernt. Eine in der Mitte durchtrennte Linie
 * wird in mehrere LineString-Features zerlegt (kein gerader „Sprung" über die
 * Zone, der das Zentrum verraten würde). Wegpunkt-`hints` in der Zone fallen
 * ebenfalls weg.
 *
 * Der Eigentümer selbst sieht seine Route ungekürzt — die Aufrufer wenden den
 * Trim nur an, wenn der Betrachter nicht der Eigentümer ist.
 */
final class RoutePrivacyTrimmer
{
    /**
     * @param array<string,mixed> $fc GeoJSON FeatureCollection
     * @return array<string,mixed> getrimmte FeatureCollection
     */
    public function trim(array $fc, PrivacyZone $zone): array
    {
        $features = is_array($fc['features'] ?? null) ? $fc['features'] : [];
        $out = [];
        foreach ($features as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'LineString'
                || !is_array($geometry['coordinates'] ?? null)) {
                // Unbekannte Geometrie defensiv unverändert durchreichen.
                $out[] = $feature;
                continue;
            }
            foreach ($this->splitOutsideZone($geometry['coordinates'], $zone) as $run) {
                $f = $feature;
                $f['geometry'] = ['type' => 'LineString', 'coordinates' => $run];
                $out[] = $f;
            }
        }
        $fc['features'] = $out;

        if (isset($fc['hints']) && is_array($fc['hints'])) {
            $fc['hints'] = array_values(array_filter(
                $fc['hints'],
                static function ($h) use ($zone): bool {
                    if (!is_array($h) || !isset($h['lat'], $h['lon'])) {
                        return true;
                    }
                    return !$zone->containsPoint((float)$h['lat'], (float)$h['lon']);
                },
            ));
            if ($fc['hints'] === []) {
                unset($fc['hints']);
            }
        }

        return $fc;
    }

    /**
     * Zerlegt eine Koordinatenfolge in zusammenhängende Läufe außerhalb der
     * Zone. Läufe mit weniger als 2 Punkten (keine echte Linie) werden
     * verworfen.
     *
     * @param list<array{0:float|int,1:float|int}> $coords [lon,lat]
     * @return list<list<array{0:float|int,1:float|int}>>
     */
    private function splitOutsideZone(array $coords, PrivacyZone $zone): array
    {
        $runs = [];
        $current = [];
        foreach ($coords as $c) {
            if (!is_array($c) || count($c) < 2) {
                continue;
            }
            $inside = $zone->containsPoint((float)$c[1], (float)$c[0]);
            if ($inside) {
                if (count($current) >= 2) {
                    $runs[] = $current;
                }
                $current = [];
                continue;
            }
            $current[] = $c;
        }
        if (count($current) >= 2) {
            $runs[] = $current;
        }
        return $runs;
    }
}
