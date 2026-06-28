<?php
declare(strict_types=1);

namespace App\Routes;

use App\Support\MapLod;

/**
 * Wandelt einen gespeicherten Routen-Payload (GPX oder GeoJSON) in eine
 * GeoJSON-{@see https://www.rfc-editor.org/rfc/rfc7946 RFC 7946}-
 * `FeatureCollection` mit einer `LineString`-Geometrie um.
 *
 * Bewusst dünn: die eigentliche Geometrie-Extraktion (inkl.
 * Format-Erkennung und Validierung) erledigt {@see GeometryParser}.
 * Diese Klasse projiziert die Punkte nur in das von Leaflet/`L.geoJSON`
 * erwartete `[lon, lat]`-Format (RFC-7946-Reihenfolge) und ist damit gut
 * unit-testbar, ohne HTTP- oder DB-Schicht.
 */
final class RouteGeoJson
{
    public function __construct(
        private readonly GeometryParser $parser,
        private readonly ?SurfaceTrack $surface = null,
    ) {
    }

    /**
     * @param array<string,mixed> $properties Optionale Feature-Properties
     *        (z. B. Name, Distanz) — landen 1:1 im `properties`-Objekt.
     * @param list<array<string,mixed>> $hints Wegpunkt-Hinweise — werden als
     *        Foreign-Member `hints` an die FeatureCollection gehängt
     *        (RFC 7946 §6.1 erlaubt zusätzliche Member). Leeres Array → kein
     *        `hints`-Feld.
     * @param array{bbox?:array{0:float,1:float,2:float,3:float}|null,max_points?:int|null}|null $lod
     *        Optionale serverseitige Detailstufe (LOD). `null` → volle
     *        Auflösung (rückwärtskompatibles Verhalten). Mit `bbox` und/oder
     *        `max_points` wird die Geometrie serverseitig auf den Ausschnitt
     *        geklippt und per Bucket-Mittelung ausgedünnt; die Antwort erhält
     *        zusätzlich ein `meta`-Objekt
     *        (`simplified`, `source_points`, `returned_points`).
     * @return array<string,mixed> GeoJSON-FeatureCollection
     */
    public function toFeatureCollection(string $payload, array $properties = [], array $hints = [], ?array $lod = null): array
    {
        $bbox      = $lod['bbox'] ?? null;
        $maxPoints = $lod['max_points'] ?? null;
        if ($bbox !== null || $maxPoints !== null) {
            return $this->toSimplifiedFeatureCollection($payload, $properties, $hints, $bbox, $maxPoints);
        }

        // Surface-Score-Einfärbung (optional): GPX mit <ge:surfaceScore>
        // liefert farbcodierte Teilsegmente. Sonst Fallback unten.
        if ($this->surface !== null) {
            $colored = $this->surface->extract($payload);
            if ($colored !== null) {
                return self::withHints($colored, $hints);
            }
        }

        $parsed = $this->parser->parse($payload);

        $coordinates = [];
        foreach ($parsed->points as $point) {
            // RFC 7946: [Längengrad, Breitengrad]
            $coordinates[] = [$point->lon, $point->lat];
        }

        return self::withHints([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => (object) $properties,
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => $coordinates,
                    ],
                ],
            ],
        ], $hints);
    }

    /**
     * Serverseitige Detailstufe (LOD): klippt die Geometrie optional auf eine
     * BBox und dünnt sie per Bucket-Mittelung auf `max_points` Punkte aus —
     * deckungsgleich zur Client-Logik ({@see MapLod}). Score-Tracks behalten
     * ihre farbcodierten Segmente (pro Bucket gerundeter Score-Mittelwert).
     *
     * @param array<string,mixed>                          $properties
     * @param list<array<string,mixed>>                    $hints
     * @param array{0:float,1:float,2:float,3:float}|null  $bbox
     * @return array<string,mixed>
     */
    private function toSimplifiedFeatureCollection(
        string $payload,
        array $properties,
        array $hints,
        ?array $bbox,
        ?int $maxPoints,
    ): array {
        // Flache Punktfolge mit optionalem Per-Punkt-Score gewinnen. GPX über
        // SurfaceTrack (kennt <ge:surfaceScore>), sonst über den GeometryParser
        // (GeoJSON/GPX ohne Scores → score null).
        $points  = null;
        $scored  = false;
        if ($this->surface !== null) {
            $gpxPoints = $this->surface->points($payload);
            if ($gpxPoints !== null) {
                $points = [];
                foreach ($gpxPoints as $p) {
                    if ($p['score'] !== null) {
                        $scored = true;
                    }
                    $points[] = ['lon' => $p['lon'], 'lat' => $p['lat'], 'score' => $p['score']];
                }
            }
        }
        if ($points === null) {
            $parsed = $this->parser->parse($payload);
            $points = [];
            foreach ($parsed->points as $point) {
                $points[] = ['lon' => $point->lon, 'lat' => $point->lat, 'score' => null];
            }
        }

        $cap = $maxPoints !== null ? max(1, $maxPoints) : null;
        $lod = MapLod::simplifyTrack($points, $bbox, $cap);

        if ($scored) {
            // Gleiche Zerlegung in farbcodierte Läufe wie bei voller Auflösung.
            $features = SurfaceTrack::segmentize($lod['points']);
        } else {
            $coordinates = [];
            foreach ($lod['points'] as $p) {
                $coordinates[] = [$p['lon'], $p['lat']];
            }
            $features = [[
                'type' => 'Feature',
                'properties' => (object) $properties,
                'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
            ]];
        }

        $fc = self::withHints(['type' => 'FeatureCollection', 'features' => $features], $hints);
        $fc['meta'] = [
            'simplified'      => $lod['simplified'],
            'source_points'   => $lod['source_points'],
            'returned_points' => $lod['returned_points'],
        ];
        return $fc;
    }

    /**
     * @param array<string,mixed> $featureCollection
     * @param list<array<string,mixed>> $hints
     * @return array<string,mixed>
     */
    private static function withHints(array $featureCollection, array $hints): array
    {
        if ($hints !== []) {
            $featureCollection['hints'] = $hints;
        }
        return $featureCollection;
    }
}
