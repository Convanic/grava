<?php
declare(strict_types=1);

namespace App\Routes;

use SimpleXMLElement;

/**
 * Extrahiert aus einer GPX-Datei mit `<ge:surfaceScore>`-Extensions
 * (vom iOS-App-Export, M5) eine GeoJSON-`FeatureCollection`, in der der
 * Track in farbcodierte Teilsegmente je Score-Lauf zerlegt ist —
 * benachbarte Punkte mit gleichem Score landen in derselben Linie.
 *
 * Greift nur bei GPX mit mindestens einem erkannten Score. Liefert
 * sonst `null`, damit {@see RouteGeoJson} auf die einfarbige Linie
 * zurückfällt. Bewusst defensiv (SimpleXML, libxml-Errors gedämpft) —
 * ein kaputter Payload darf die Karte nicht crashen.
 */
final class SurfaceTrack
{
    private const NS_GPX = 'http://www.topografix.com/GPX/1/1';
    private const NS_GE  = 'https://gravelexplorer.benx.de/gpx/v1';

    /**
     * @return array<string,mixed>|null GeoJSON-FeatureCollection oder null
     */
    public function extract(string $gpx): ?array
    {
        $sniff = GeometryParser::sniffFormat($gpx);
        if ($sniff !== 'gpx') {
            return null;
        }

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
        if (!$xml instanceof SimpleXMLElement) {
            return null;
        }

        $xml->registerXPathNamespace('g', self::NS_GPX);
        $points = $xml->xpath('//g:trkpt');
        if ($points === false || $points === null || count($points) < 2) {
            return null;
        }

        $parsed = [];
        $scoredCount = 0;
        foreach ($points as $pt) {
            $lat = isset($pt['lat']) ? (float)$pt['lat'] : null;
            $lon = isset($pt['lon']) ? (float)$pt['lon'] : null;
            if ($lat === null || $lon === null) {
                continue;
            }
            $score = self::readScore($pt);
            if ($score !== null) {
                $scoredCount++;
            }
            $parsed[] = ['lon' => $lon, 'lat' => $lat, 'score' => $score];
        }

        if ($scoredCount === 0 || count($parsed) < 2) {
            return null;
        }

        return ['type' => 'FeatureCollection', 'features' => self::segmentize($parsed)];
    }

    /**
     * Liefert ALLE Trackpunkte in Reihenfolge mit optionalem Surface-Score —
     * auch wenn kein einziger Score gesetzt ist (anders als {@see extract()},
     * das dann null liefert). Gedacht fürs Map-Matching: Reihenfolge der Punkte
     * korrespondiert 1:1 mit Valhallas `matched_points`.
     *
     * @return list<array{lat:float,lon:float,score:?int}>|null  null wenn kein GPX
     */
    public function points(string $gpx): ?array
    {
        if (GeometryParser::sniffFormat($gpx) !== 'gpx') {
            return null;
        }

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
        if (!$xml instanceof SimpleXMLElement) {
            return null;
        }

        $xml->registerXPathNamespace('g', self::NS_GPX);
        $nodes = $xml->xpath('//g:trkpt');
        if ($nodes === false || $nodes === null || count($nodes) < 2) {
            return null;
        }

        $out = [];
        foreach ($nodes as $pt) {
            $lat = isset($pt['lat']) ? (float)$pt['lat'] : null;
            $lon = isset($pt['lon']) ? (float)$pt['lon'] : null;
            if ($lat === null || $lon === null) {
                continue;
            }
            $out[] = ['lat' => $lat, 'lon' => $lon, 'score' => self::readScore($pt)];
        }

        return count($out) >= 2 ? $out : null;
    }

    private static function readScore(SimpleXMLElement $trkpt): ?int
    {
        $ext = $trkpt->children(self::NS_GPX)->extensions ?? null;
        if ($ext === null || $ext->count() === 0) {
            // Manche Exporte hängen die Extension ohne GPX-Namespace an.
            $ext = $trkpt->extensions ?? null;
        }
        if ($ext === null) {
            return null;
        }
        $ge = $ext->children(self::NS_GE);
        if ($ge === null || !isset($ge->surfaceScore)) {
            return null;
        }
        $raw = trim((string)$ge->surfaceScore);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return max(0, min(5, (int)round((float)$raw)));
    }

    /**
     * Zerlegt die Punktfolge in Läufe gleichen Scores. Der Grenzpunkt
     * gehört zu beiden angrenzenden Segmenten, damit die Linie lückenlos
     * bleibt. Public, damit {@see RouteGeoJson} die (ggf. serverseitig
     * ausgedünnte) Punktfolge in dieselben farbcodierten Features zerlegen
     * kann — optisch deckungsgleich zur vollen Auflösung.
     *
     * @param list<array{lon:float,lat:float,score:?int}> $points
     * @return list<array<string,mixed>>
     */
    public static function segmentize(array $points): array
    {
        $features = [];
        $run = [];
        $runScore = null;
        $started = false;

        foreach ($points as $p) {
            $coord = [$p['lon'], $p['lat']];
            if (!$started) {
                $run = [$coord];
                $runScore = $p['score'];
                $started = true;
                continue;
            }
            if ($p['score'] === $runScore) {
                $run[] = $coord;
            } else {
                $run[] = $coord; // Grenzpunkt schließt das laufende Segment
                $features[] = self::feature($run, $runScore);
                $run = [$coord]; // und eröffnet das neue
                $runScore = $p['score'];
            }
        }
        if (count($run) >= 2) {
            $features[] = self::feature($run, $runScore);
        }
        return $features;
    }

    /**
     * @param list<array{0:float,1:float}> $coords
     * @return array<string,mixed>
     */
    private static function feature(array $coords, ?int $score): array
    {
        return [
            'type' => 'Feature',
            'properties' => $score === null ? (object) [] : ['score' => $score],
            'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
        ];
    }
}
