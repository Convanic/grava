<?php
declare(strict_types=1);

namespace App\Routes;

use SimpleXMLElement;

/**
 * Parst die Ride-Aggregate für Leistung, Trittfrequenz, Pedal-Balance und
 * Herzfrequenz aus dem GPX-`<metadata>` (PowerData_Backend_Spec.md). Alle im
 * `ge:`-Namespace ({@see self::NS_GE}, wie die Radar-/Hinweis-Extensions):
 *
 * ```xml
 * <metadata><extensions>
 *   <ge:avgPower>210</ge:avgPower>
 *   <ge:maxPower>540</ge:maxPower>
 *   <ge:avgCadence>88</ge:avgCadence>
 *   <ge:avgPedalBalance>52.0</ge:avgPedalBalance>
 *   <ge:avgHeartRate>142</ge:avgHeartRate>
 *   <ge:maxHeartRate>176</ge:maxHeartRate>
 * </extensions></metadata>
 * ```
 *
 * Die volle Per-Trackpoint-Zeitreihe (`<power>`, `<gpxtpx:cad>`, `<gpxtpx:hr>`)
 * bleibt bewusst ungenutzt — die Aggregate genügen für die Anzeige, der Client
 * hält die Zeitreihe lokal. Defensiv (SimpleXML, libxml-Errors gedämpft): ein
 * kaputter Payload darf den Upload nicht crashen → im Zweifel leere Metriken.
 */
final class SensorMetricsParser
{
    private const NS_GE = 'https://gravelexplorer.benx.de/gpx/v1';

    public static function parse(string $payload): SensorMetrics
    {
        if (GeometryParser::sniffFormat($payload) !== 'gpx') {
            return SensorMetrics::empty();
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
            return SensorMetrics::empty();
        }
        $xml->registerXPathNamespace('ge', self::NS_GE);

        return new SensorMetrics(
            avgPowerW:          self::readInt($xml, 'avgPower'),
            maxPowerW:          self::readInt($xml, 'maxPower'),
            avgCadenceRpm:      self::readInt($xml, 'avgCadence'),
            avgPedalBalancePct: self::readFloat($xml, 'avgPedalBalance', 0.0, 100.0),
            avgHeartRateBpm:    self::readInt($xml, 'avgHeartRate'),
            maxHeartRateBpm:    self::readInt($xml, 'maxHeartRate'),
        );
    }

    /** Non-negative integer from a `<ge:$name>` metadata node, else null. */
    private static function readInt(SimpleXMLElement $xml, string $name): ?int
    {
        $raw = self::readRaw($xml, $name);
        if ($raw === null || !is_numeric($raw)) {
            return null;
        }
        $v = (int)round((float)$raw);
        return $v >= 0 ? $v : null;
    }

    /** Float within [$min,$max] from a `<ge:$name>` node, else null. */
    private static function readFloat(SimpleXMLElement $xml, string $name, float $min, float $max): ?float
    {
        $raw = self::readRaw($xml, $name);
        if ($raw === null || !is_numeric($raw)) {
            return null;
        }
        $v = (float)$raw;
        return ($v >= $min && $v <= $max) ? $v : null;
    }

    private static function readRaw(SimpleXMLElement $xml, string $name): ?string
    {
        $nodes = $xml->xpath("//ge:{$name}");
        if ($nodes === false || $nodes === null || $nodes === []) {
            return null;
        }
        $raw = trim((string)$nodes[0]);
        return $raw === '' ? null : $raw;
    }
}
