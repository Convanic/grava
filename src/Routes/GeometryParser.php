<?php
declare(strict_types=1);

namespace App\Routes;

use DateTimeImmutable;
use DateTimeZone;
use phpGPX\phpGPX;

/**
 * Parst Track-Geometrien aus dem rohen Upload-Body.
 *
 * Unterstützte Formate:
 *  - **GPX** (XML mit Wurzelelement `<gpx ...>`) — über
 *    {@link https://github.com/Sibyx/phpGPX `sibyx/phpgpx` 2.x}.
 *  - **GeoJSON** ({@see https://www.rfc-editor.org/rfc/rfc7946 RFC 7946}) —
 *    eigene, defensive Implementierung. Akzeptiert:
 *      * `LineString`-Geometrie direkt
 *      * `Feature` mit `LineString`-Geometrie
 *      * `FeatureCollection` mit einem oder mehreren LineString-Features
 *
 * Format-Erkennung läuft strukturell, nicht über den vom Client
 * gesetzten MIME-Type — der ist nicht vertrauenswürdig.
 *
 * Validierung schlägt **früh** fehl mit
 * {@see GeometryParseException}, sobald
 *  - Lat/Lon außerhalb des gültigen Bereichs liegen,
 *  - weniger als zwei Punkte vorhanden sind,
 *  - das Format weder GPX noch GeoJSON ist,
 *  - die Datei syntaktisch kaputt ist.
 *
 * Zeitstempel werden in UTC normalisiert — nachgelagerte Schichten
 * (Repository, Mail-Templates) müssen sich keine Zeitzonen merken.
 */
final class GeometryParser
{
    public function parse(string $payload): ParsedRoute
    {
        $sniff = self::sniffFormat($payload);
        return match ($sniff) {
            'gpx'     => $this->parseGpx($payload),
            'geojson' => $this->parseGeoJson($payload),
            default   => throw new GeometryParseException(
                'Unbekanntes Format. Erwartet wird GPX (XML) oder GeoJSON.',
            ),
        };
    }

    /**
     * Schaut auf das erste signifikante Zeichen. Liefert 'gpx',
     * 'geojson' oder null wenn unklar.
     */
    public static function sniffFormat(string $payload): ?string
    {
        $trimmed = ltrim($payload);
        if ($trimmed === '') {
            return null;
        }
        $first = $trimmed[0];
        if ($first === '<') {
            return 'gpx';
        }
        if ($first === '{' || $first === '[') {
            return 'geojson';
        }
        return null;
    }

    private function parseGpx(string $xml): ParsedRoute
    {
        // libxml-Errors einsammeln statt im Output landen lassen.
        $previousUseInternal = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $gpxFile = (new phpGPX())->parse($xml);
        } catch (\Throwable $e) {
            $errors = self::collectLibxmlErrors();
            $detail = $errors !== '' ? " ({$errors})" : '';
            throw new GeometryParseException(
                'GPX-Datei kann nicht gelesen werden' . $detail,
                previous: $e,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternal);
        }

        $points = [];
        $startedAt = null;
        $endedAt   = null;

        foreach ($gpxFile->tracks as $track) {
            // Track::getPoints() flacht alle Segmente in eine Liste auf.
            foreach ($track->getPoints() as $p) {
                if ($p->latitude === null || $p->longitude === null) {
                    continue;
                }
                self::assertLatLon((float)$p->latitude, (float)$p->longitude);

                $time = $p->time !== null
                    ? DateTimeImmutable::createFromInterface($p->time)->setTimezone(new DateTimeZone('UTC'))
                    : null;
                if ($time !== null) {
                    if ($startedAt === null || $time < $startedAt) {
                        $startedAt = $time;
                    }
                    if ($endedAt === null || $time > $endedAt) {
                        $endedAt = $time;
                    }
                }
                $points[] = new ParsedPoint(
                    lat: (float)$p->latitude,
                    lon: (float)$p->longitude,
                    elevationM: $p->elevation !== null ? (float)$p->elevation : null,
                    timestamp: $time,
                );
            }
        }

        // Falls die GPX nur Routen oder Waypoints, aber keine Tracks hatte,
        // versuchen wir `routes` als zweite Quelle. Routes haben pro Eintrag
        // eine Punkteliste in der Property `points`.
        if ($points === []) {
            foreach ($gpxFile->routes as $route) {
                foreach ($route->points as $p) {
                    if ($p->latitude === null || $p->longitude === null) {
                        continue;
                    }
                    self::assertLatLon((float)$p->latitude, (float)$p->longitude);
                    $time = $p->time !== null
                        ? DateTimeImmutable::createFromInterface($p->time)->setTimezone(new DateTimeZone('UTC'))
                        : null;
                    if ($time !== null) {
                        if ($startedAt === null || $time < $startedAt) {
                            $startedAt = $time;
                        }
                        if ($endedAt === null || $time > $endedAt) {
                            $endedAt = $time;
                        }
                    }
                    $points[] = new ParsedPoint(
                        lat: (float)$p->latitude,
                        lon: (float)$p->longitude,
                        elevationM: $p->elevation !== null ? (float)$p->elevation : null,
                        timestamp: $time,
                    );
                }
            }
        }

        if (count($points) < 2) {
            throw new GeometryParseException('GPX-Datei enthält weniger als zwei Punkte.');
        }

        return new ParsedRoute(
            points: $points,
            sourceFormat: 'gpx',
            startedAt: $startedAt,
            endedAt: $endedAt,
            elevationGainOverrideM: self::readElevationGainOverride($xml),
        );
    }

    /**
     * Liest die optionale `<ge:elevationGain>`-Extension (exakter, i. d. R.
     * barometrischer Gesamtanstieg des iOS-Clients). Namespace wie bei
     * {@see SurfaceTrack} (`ge:surfaceScore`). Defensiv: ein kaputter Payload
     * darf hier nicht crashen — bei jedem Zweifel `null` (→ Berechnung aus
     * `<ele>`). Sitzt typischerweise in `<metadata>` oder `<trk>`, daher
     * positionsunabhängige XPath-Suche.
     */
    private static function readElevationGainOverride(string $gpx): ?float
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $xml = simplexml_load_string($gpx);
        } catch (\Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if (!$xml instanceof \SimpleXMLElement) {
            return null;
        }
        $xml->registerXPathNamespace('ge', 'https://gravelexplorer.benx.de/gpx/v1');
        $nodes = $xml->xpath('//ge:elevationGain');
        if ($nodes === false || $nodes === null || $nodes === []) {
            return null;
        }
        $raw = trim((string)$nodes[0]);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $val = (float)$raw;
        return $val >= 0.0 ? $val : null;
    }

    private function parseGeoJson(string $json): ParsedRoute
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GeometryParseException(
                'GeoJSON-Datei ist kein gültiges JSON: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new GeometryParseException('GeoJSON-Wurzelelement muss ein Objekt sein.');
        }

        $coordinatesGroups = self::extractLineStringCoords($data);
        if ($coordinatesGroups === []) {
            throw new GeometryParseException(
                'GeoJSON enthält keine LineString-Geometrie.',
            );
        }

        $points = [];
        foreach ($coordinatesGroups as $coords) {
            foreach ($coords as $i => $coord) {
                if (!is_array($coord) || count($coord) < 2) {
                    throw new GeometryParseException(
                        "GeoJSON-Koordinate #{$i} ist keine valide [lon, lat]-Tupel.",
                    );
                }
                // RFC 7946: [lon, lat, (alt)]
                $lon = (float)$coord[0];
                $lat = (float)$coord[1];
                $alt = isset($coord[2]) ? (float)$coord[2] : null;
                self::assertLatLon($lat, $lon);
                $points[] = new ParsedPoint(
                    lat: $lat,
                    lon: $lon,
                    elevationM: $alt,
                    timestamp: null,
                );
            }
        }

        if (count($points) < 2) {
            throw new GeometryParseException('GeoJSON enthält weniger als zwei Punkte.');
        }

        return new ParsedRoute(
            points: $points,
            sourceFormat: 'geojson',
            startedAt: null,
            endedAt: null,
        );
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array<int,array<int,float|int>>>  Liste von Koordinaten-Listen
     */
    private static function extractLineStringCoords(array $data): array
    {
        $type = $data['type'] ?? null;

        if ($type === 'LineString') {
            return [self::asCoordList($data['coordinates'] ?? null)];
        }

        if ($type === 'Feature') {
            $geom = $data['geometry'] ?? null;
            if (is_array($geom)) {
                return self::extractLineStringCoords($geom);
            }
            return [];
        }

        if ($type === 'FeatureCollection') {
            $groups = [];
            $features = $data['features'] ?? null;
            if (is_array($features)) {
                foreach ($features as $f) {
                    if (is_array($f)) {
                        foreach (self::extractLineStringCoords($f) as $g) {
                            $groups[] = $g;
                        }
                    }
                }
            }
            return $groups;
        }

        if ($type === 'GeometryCollection') {
            $groups = [];
            $geoms = $data['geometries'] ?? null;
            if (is_array($geoms)) {
                foreach ($geoms as $g) {
                    if (is_array($g)) {
                        foreach (self::extractLineStringCoords($g) as $coords) {
                            $groups[] = $coords;
                        }
                    }
                }
            }
            return $groups;
        }

        if ($type === 'MultiLineString') {
            $groups = [];
            $coords = $data['coordinates'] ?? null;
            if (is_array($coords)) {
                foreach ($coords as $line) {
                    $groups[] = self::asCoordList($line);
                }
            }
            return $groups;
        }

        return [];
    }

    /**
     * @param mixed $value
     * @return array<int,array<int,float|int>>
     */
    private static function asCoordList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_array'));
    }

    private static function assertLatLon(float $lat, float $lon): void
    {
        if ($lat < -90.0 || $lat > 90.0) {
            throw new GeometryParseException(
                'Latitude außerhalb des erlaubten Bereichs (-90..90): ' . $lat,
            );
        }
        if ($lon < -180.0 || $lon > 180.0) {
            throw new GeometryParseException(
                'Longitude außerhalb des erlaubten Bereichs (-180..180): ' . $lon,
            );
        }
    }

    private static function collectLibxmlErrors(): string
    {
        $errors = libxml_get_errors();
        if ($errors === []) {
            return '';
        }
        $msgs = [];
        foreach ($errors as $err) {
            $msgs[] = trim($err->message);
        }
        return implode('; ', array_slice($msgs, 0, 3));
    }
}
