<?php
declare(strict_types=1);

namespace App\Routes;

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
     * @return array<string,mixed> GeoJSON-FeatureCollection
     */
    public function toFeatureCollection(string $payload, array $properties = []): array
    {
        // Surface-Score-Einfärbung (optional): GPX mit <ge:surfaceScore>
        // liefert farbcodierte Teilsegmente. Sonst Fallback unten.
        if ($this->surface !== null) {
            $colored = $this->surface->extract($payload);
            if ($colored !== null) {
                return $colored;
            }
        }

        $parsed = $this->parser->parse($payload);

        $coordinates = [];
        foreach ($parsed->points as $point) {
            // RFC 7946: [Längengrad, Breitengrad]
            $coordinates[] = [$point->lon, $point->lat];
        }

        return [
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
        ];
    }
}
