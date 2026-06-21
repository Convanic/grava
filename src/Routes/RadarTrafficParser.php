<?php
declare(strict_types=1);

namespace App\Routes;

use SimpleXMLElement;

/**
 * Parst Radar-/Verkehrsdaten aus einer GPX-Datei (RADAR_TRAFFIC_BACKEND.md §A3).
 *
 * Zwei Ebenen, beide im `ge:`-Namespace ({@see self::NS_GE}, wie die
 * Wegpunkt-Hinweise):
 *
 *  1. Ride-Aggregat im `<metadata>`:
 *     ```xml
 *     <metadata><extensions>
 *       <ge:trafficPassesPerKm>3.4</ge:trafficPassesPerKm>
 *     </extensions></metadata>
 *     ```
 *  2. Pro Vorbeifahrt ein `<wpt>` (vor dem `<trk>`):
 *     ```xml
 *     <wpt lat="48.20" lon="12.41"><time>…</time>
 *       <extensions><ge:vehiclePass>1</ge:vehiclePass></extensions></wpt>
 *     ```
 *
 * Defensiv (SimpleXML, libxml-Errors gedämpft): ein kaputter Payload darf
 * den Upload nicht crashen — im Zweifel neutrale, leere Daten. Fremd-`<wpt>`
 * ohne `ge:vehiclePass` werden ignoriert (kein 422), genau wie die Hinweise.
 */
final class RadarTrafficParser
{
    private const NS_GPX = 'http://www.topografix.com/GPX/1/1';
    private const NS_GE  = 'https://gravelexplorer.benx.de/gpx/v1';

    public static function parse(string $payload): RadarTrafficData
    {
        if (GeometryParser::sniffFormat($payload) !== 'gpx') {
            return RadarTrafficData::empty();
        }

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $xml = simplexml_load_string($payload);
        } catch (\Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        if (!$xml instanceof SimpleXMLElement) {
            return RadarTrafficData::empty();
        }

        return new RadarTrafficData(
            self::readPassesPerKm($xml),
            self::readVehiclePasses($xml),
        );
    }

    private static function readPassesPerKm(SimpleXMLElement $xml): ?float
    {
        $xml->registerXPathNamespace('ge', self::NS_GE);
        $nodes = $xml->xpath('//ge:trafficPassesPerKm');
        if ($nodes === false || $nodes === null || $nodes === []) {
            return null;
        }
        $raw = trim((string)$nodes[0]);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        $v = (float)$raw;
        // Negative/absurde Werte verwerfen (defensiv).
        return $v >= 0.0 ? $v : null;
    }

    /** @return list<array{0:float,1:float}> [lat, lon] */
    private static function readVehiclePasses(SimpleXMLElement $xml): array
    {
        $xml->registerXPathNamespace('g', self::NS_GPX);
        $wpts = $xml->xpath('//g:wpt');
        if ($wpts === false || $wpts === null || $wpts === []) {
            $wpts = $xml->xpath('//wpt') ?: [];
        }

        $out = [];
        foreach ($wpts as $wpt) {
            if (!self::hasVehiclePass($wpt)) {
                continue;
            }
            $lat = isset($wpt['lat']) ? (float)$wpt['lat'] : null;
            $lon = isset($wpt['lon']) ? (float)$wpt['lon'] : null;
            if ($lat === null || $lon === null) {
                continue;
            }
            if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
                continue;
            }
            $out[] = [$lat, $lon];
        }
        return $out;
    }

    private static function hasVehiclePass(SimpleXMLElement $wpt): bool
    {
        $ext = $wpt->children(self::NS_GPX)->extensions ?? null;
        if ($ext === null || $ext->count() === 0) {
            $ext = $wpt->extensions ?? null;
        }
        if ($ext === null) {
            return false;
        }
        $ge = $ext->children(self::NS_GE);
        return $ge !== null && isset($ge->vehiclePass);
    }
}
