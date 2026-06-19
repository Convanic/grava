<?php
declare(strict_types=1);

namespace App\Routes;

use DateTimeImmutable;
use DateTimeZone;
use SimpleXMLElement;

/**
 * Parst Wegpunkt-Hinweise aus einer GPX-Datei.
 *
 * Die iOS-App hängt die Hinweise als Standard-`<wpt>` (vor dem `<trk>`) an,
 * mit einer `ge:`-Extension (Namespace {@see self::NS_GE}):
 *
 * ```xml
 * <wpt lat="47.123456" lon="9.654321">
 *   <time>2026-06-19T09:31:12Z</time>
 *   <name>Unfahrbar / Umkehren</name>
 *   <desc>Brücke weggespült</desc>
 *   <extensions>
 *     <ge:hintReason>unrideable</ge:hintReason>
 *     <ge:hintSentiment>negative</ge:hintSentiment>
 *     <ge:hintSymbol>xmark.octagon.fill</ge:hintSymbol>
 *   </extensions>
 * </wpt>
 * ```
 *
 * Nur `<wpt>` mit gültigem `ge:hintReason` werden berücksichtigt — Fremd-GPX
 * mit beliebigen Waypoints (ohne unsere Extension) wird ignoriert. Einzelne
 * ungültige Hinweise werden übersprungen, nicht der ganze Upload abgebrochen.
 * Defensive Implementierung (SimpleXML, libxml-Errors gedämpft): ein kaputter
 * Payload darf den Upload nicht crashen — im Zweifel leere Liste.
 */
final class RouteHintParser
{
    private const NS_GPX = 'http://www.topografix.com/GPX/1/1';
    private const NS_GE  = 'https://gravelexplorer.benx.de/gpx/v1';

    private const MAX_LABEL = 80;
    private const MAX_NOTE  = 280;

    /**
     * @return list<ParsedHint>
     */
    public function parse(string $payload): array
    {
        if (GeometryParser::sniffFormat($payload) !== 'gpx') {
            // GeoJSON & Co. tragen keine Hinweise — leere Liste.
            return [];
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
            return [];
        }

        $xml->registerXPathNamespace('g', self::NS_GPX);
        // Erst mit GPX-Namespace, dann ohne (manche Exporte lassen die
        // Default-Namespace-Deklaration weg).
        $wpts = $xml->xpath('//g:wpt');
        if ($wpts === false || $wpts === null || $wpts === []) {
            $wpts = $xml->xpath('//wpt') ?: [];
        }

        $out = [];
        foreach ($wpts as $wpt) {
            $hint = self::parseWpt($wpt);
            if ($hint !== null) {
                $out[] = $hint;
            }
        }
        return $out;
    }

    private static function parseWpt(SimpleXMLElement $wpt): ?ParsedHint
    {
        $reason = self::readGe($wpt, 'hintReason');
        if ($reason === null) {
            // Kein Hinweis-Waypoint (Fremd-GPX) → überspringen.
            return null;
        }
        $reasonKey = strtolower(trim($reason));
        if (preg_match('/^[a-z0-9_]{1,40}$/', $reasonKey) !== 1) {
            return null;
        }

        $sentiment = strtolower(trim((string)self::readGe($wpt, 'hintSentiment')));
        if ($sentiment !== 'negative' && $sentiment !== 'positive') {
            return null;
        }

        $lat = isset($wpt['lat']) ? (float)$wpt['lat'] : null;
        $lon = isset($wpt['lon']) ? (float)$wpt['lon'] : null;
        if ($lat === null || $lon === null) {
            return null;
        }
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return null;
        }

        $label = self::readGpxChild($wpt, 'name');
        $label = $label !== null ? trim($label) : '';
        if ($label === '') {
            // label ist NOT NULL — sinnvoller Fallback auf den reason_key.
            $label = $reasonKey;
        }
        $label = mb_substr($label, 0, self::MAX_LABEL);

        $note = self::readGpxChild($wpt, 'desc');
        if ($note !== null) {
            $note = trim($note);
            $note = $note === '' ? null : mb_substr($note, 0, self::MAX_NOTE);
        }

        $recordedAt = null;
        $timeRaw = self::readGpxChild($wpt, 'time');
        if ($timeRaw !== null && trim($timeRaw) !== '') {
            try {
                $recordedAt = (new DateTimeImmutable(trim($timeRaw)))
                    ->setTimezone(new DateTimeZone('UTC'));
            } catch (\Throwable) {
                $recordedAt = null;
            }
        }

        return new ParsedHint(
            reasonKey: $reasonKey,
            sentiment: $sentiment,
            label: $label,
            note: $note,
            lat: $lat,
            lon: $lon,
            recordedAt: $recordedAt,
        );
    }

    /**
     * Liest ein Kind-Element aus dem GPX-Namespace (z. B. name/desc/time),
     * mit Fallback auf den namespacelosen Knoten.
     */
    private static function readGpxChild(SimpleXMLElement $wpt, string $name): ?string
    {
        $gpx = $wpt->children(self::NS_GPX);
        if ($gpx !== null && isset($gpx->{$name})) {
            return (string)$gpx->{$name};
        }
        $plain = $wpt->children();
        if ($plain !== null && isset($plain->{$name})) {
            return (string)$plain->{$name};
        }
        return null;
    }

    /**
     * Liest ein ge:-Extension-Feld (hintReason/hintSentiment) aus dem
     * <extensions>-Block. Toleriert Extensions mit und ohne GPX-Namespace.
     */
    private static function readGe(SimpleXMLElement $wpt, string $field): ?string
    {
        $ext = $wpt->children(self::NS_GPX)->extensions ?? null;
        if ($ext === null || $ext->count() === 0) {
            $ext = $wpt->extensions ?? null;
        }
        if ($ext === null) {
            return null;
        }
        $ge = $ext->children(self::NS_GE);
        if ($ge === null || !isset($ge->{$field})) {
            return null;
        }
        $raw = (string)$ge->{$field};
        return $raw === '' ? null : $raw;
    }
}
