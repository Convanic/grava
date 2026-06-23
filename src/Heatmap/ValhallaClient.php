<?php
declare(strict_types=1);

namespace App\Heatmap;

/**
 * Dünner HTTP-Client für Valhallas `trace_attributes` (Map-Matching).
 *
 * Wird ausschließlich im Precompute des {@see HeatmapLinesService} benutzt,
 * NIE im Request-Pfad. Bewusst defensiv: jeder Fehler (Timeout, Nicht-200,
 * kaputtes JSON, Fehler-Response wie `error_code 171`) führt zu `null`,
 * sodass der Rebuild die betroffene Route einfach überspringt.
 *
 * Wichtig (Spike-Erkenntnisse, siehe docs/PLAN_HEATMAP_MAPMATCH.md §3a):
 *  - Request OHNE `filters` — sonst filtert Valhalla `matched_points` weg.
 *  - `shape` ist eine encoded Polyline mit precision 1e6.
 *  - `edge.length` ist in den Response-`units` (Default Kilometer).
 */
// Nicht final: erlaubt schlanke Test-Doubles (z. B. für ValhallaEdgeMatcher),
// ohne ein eigenes Interface einzuführen.
class ValhallaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $costing = 'bicycle',
        private readonly int $timeoutSeconds = 30,
    ) {}

    /**
     * Matcht eine GPS-Spur aufs Straßennetz.
     *
     * @param list<array{lat:float,lon:float}> $points
     */
    public function matchTrace(array $points): ?ValhallaMatch
    {
        if (count($points) < 2) {
            return null;
        }

        $shape = [];
        foreach ($points as $p) {
            if (!isset($p['lat'], $p['lon'])) {
                continue;
            }
            $shape[] = ['lat' => (float)$p['lat'], 'lon' => (float)$p['lon']];
        }
        if (count($shape) < 2) {
            return null;
        }

        $body = json_encode([
            'shape'       => $shape,
            'costing'     => $this->costing,
            'shape_match' => 'map_snap',
        ], JSON_THROW_ON_ERROR);

        $json = $this->post('/trace_attributes', $body, count($shape));
        if ($json === null) {
            // Grund (Transport-Fehler ODER HTTP 4xx/5xx mit Body) wurde bereits
            // in post() geloggt.
            return null;
        }
        $match = self::parse($json);
        if ($match === null) {
            // 200 OK, aber kein verwertbares Match (kein `edges`, Fehler-Body wie
            // error_code 444 „map_snap failed", oder kaputtes JSON). KEIN
            // Erreichbarkeitsproblem — fürs Debugging trotzdem die rohe Antwort.
            error_log(sprintf(
                'Valhalla /trace_attributes: HTTP 200, aber nicht parsebar/ohne edges. points=%d costing=%s shape_match=map_snap resp=%.500s',
                count($shape),
                $this->costing,
                $json,
            ));
        }
        return $match;
    }

    /** Die konfigurierte Basis-URL (für Diagnose/Anzeige im Admin-Dashboard). */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Health-Ping gegen `GET /status` (kurzer Timeout). Liefert ein flaches
     * Status-Array für das Admin-Dashboard — wirft NIE (Unerreichbarkeit ist
     * ein gültiges Ergebnis, kein Fehler).
     *
     * @return array{reachable:bool,base_url:string,version:?string,
     *               tileset_last_modified:?string,latency_ms:?int,error:?string}
     */
    public function status(): array
    {
        $result = [
            'reachable'             => false,
            'base_url'              => $this->baseUrl,
            'version'               => null,
            'tileset_last_modified' => null,
            'latency_ms'            => null,
            'error'                 => null,
        ];

        $ch = curl_init(rtrim($this->baseUrl, '/') . '/status');
        if ($ch === false) {
            $result['error'] = 'curl_init fehlgeschlagen';
            return $result;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);
        $start = microtime(true);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $result['latency_ms'] = (int)round((microtime(true) - $start) * 1000);

        if ($resp === false || !is_string($resp)) {
            $result['error'] = $err !== '' ? $err : 'keine Antwort';
            return $result;
        }
        if ($code !== 200) {
            $result['error'] = 'HTTP ' . $code;
            return $result;
        }
        return array_merge($result, self::parseStatus($resp));
    }

    /**
     * Parst eine `/status`-Antwort. Statisch + ohne I/O → testbar.
     *
     * @return array{reachable:bool,version:?string,tileset_last_modified:?string,error:?string}
     */
    public static function parseStatus(string $json): array
    {
        try {
            $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['reachable' => false, 'version' => null, 'tileset_last_modified' => null, 'error' => 'ungültiges JSON'];
        }
        if (!is_array($d)) {
            return ['reachable' => false, 'version' => null, 'tileset_last_modified' => null, 'error' => 'unerwartete Antwort'];
        }
        $ts = isset($d['tileset_last_modified']) && is_numeric($d['tileset_last_modified'])
            ? (int)$d['tileset_last_modified']
            : null;
        return [
            'reachable'             => true,
            'version'               => isset($d['version']) && is_string($d['version']) ? $d['version'] : null,
            'tileset_last_modified' => $ts !== null ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null,
            'error'                 => null,
        ];
    }

    /**
     * Parst eine `trace_attributes`-Antwort. Statisch + ohne I/O, damit
     * gegen eine Fixture testbar.
     */
    public static function parse(string $json): ?ValhallaMatch
    {
        try {
            $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($d) || !isset($d['edges']) || !is_array($d['edges'])) {
            return null;
        }

        $shape = (isset($d['shape']) && is_string($d['shape']))
            ? self::decodePolyline($d['shape'])
            : [];

        // edge.length ist in den Response-units; Default ist Kilometer.
        $units = is_string($d['units'] ?? null) ? $d['units'] : 'kilometers';
        $toMeters = ($units === 'miles') ? 1609.344 : 1000.0;

        $edges = [];
        foreach ($d['edges'] as $e) {
            if (!is_array($e)) {
                continue;
            }
            $begin = $e['begin_shape_index'] ?? null;
            $end   = $e['end_shape_index'] ?? null;
            $geom = [];
            if (is_int($begin) && is_int($end) && $end >= $begin && $shape !== []) {
                $geom = array_values(array_slice($shape, $begin, $end - $begin + 1));
            }
            $edges[] = new ValhallaMatchedEdge(
                valhallaId: (int)($e['id'] ?? 0),
                wayId: isset($e['way_id']) ? (int)$e['way_id'] : null,
                lengthM: (float)($e['length'] ?? 0) * $toMeters,
                geometry: $geom,
                surface: isset($e['surface']) && is_string($e['surface']) ? $e['surface'] : null,
            );
        }

        $matchedPoints = [];
        $mps = $d['matched_points'] ?? [];
        if (is_array($mps)) {
            foreach ($mps as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $matchedPoints[] = [
                    'edgeIndex' => isset($m['edge_index']) ? (int)$m['edge_index'] : -1,
                    'type'      => is_string($m['type'] ?? null) ? $m['type'] : 'unmatched',
                    'lat'       => (float)($m['lat'] ?? 0),
                    'lon'       => (float)($m['lon'] ?? 0),
                ];
            }
        }

        return new ValhallaMatch($edges, $matchedPoints);
    }

    /**
     * Dekodiert eine Google/Valhalla-encoded Polyline in [lon, lat]-Paare.
     *
     * @return list<array{0:float,1:float}>
     */
    public static function decodePolyline(string $encoded, int $precision = 6): array
    {
        $factor = 10 ** $precision;
        $len = strlen($encoded);
        $index = 0;
        $lat = 0;
        $lon = 0;
        $coords = [];

        while ($index < $len) {
            $shift = 0;
            $result = 0;
            do {
                if ($index >= $len) {
                    return $coords;
                }
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $lat += ($result & 1) ? ~($result >> 1) : ($result >> 1);

            $shift = 0;
            $result = 0;
            do {
                if ($index >= $len) {
                    return $coords;
                }
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $lon += ($result & 1) ? ~($result >> 1) : ($result >> 1);

            $coords[] = [$lon / $factor, $lat / $factor];
        }

        return $coords;
    }

    private function post(string $path, string $body, int $pointCount = 0): ?string
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            error_log('Valhalla ' . $path . ': curl_init fehlgeschlagen für ' . $url);
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp  = curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);

        // Echter Transport-/Verbindungsfehler → Engine NICHT erreichbar
        // (retrybar). Wird als ValhallaUnavailableException nach oben gereicht.
        if ($resp === false || $errno !== 0) {
            $msg = sprintf(
                'Valhalla %s: Transport-Fehler errno=%d "%s" url=%s costing=%s shape_match=map_snap points=%d body_len=%d timeout=%ds',
                $path, $errno, $err, $url, $this->costing, $pointCount, strlen($body), $this->timeoutSeconds,
            );
            error_log($msg);
            throw new ValhallaUnavailableException($msg);
        }
        // 5xx → Engine antwortet, ist aber serverseitig gestört → ebenfalls
        // „nicht verfügbar" (retrybar).
        if ($code >= 500) {
            $msg = sprintf(
                'Valhalla %s: HTTP %d (Server-Fehler) url=%s points=%d resp=%.500s',
                $path, $code, $url, $pointCount, is_string($resp) ? $resp : '',
            );
            error_log($msg);
            throw new ValhallaUnavailableException($msg);
        }
        // 4xx → Engine HAT geantwortet, nur kein verwertbares Match (z. B.
        // 400/444 „map_snap failed", Spur snappt nicht). KEIN
        // Erreichbarkeitsproblem → null (Aufrufer behandelt als „kein Match").
        // Rohe Antwort (erste ~500 Bytes) fürs Debugging loggen.
        if ($code !== 200 || !is_string($resp)) {
            error_log(sprintf(
                'Valhalla %s: HTTP %d (kein Match) url=%s costing=%s shape_match=map_snap points=%d body_len=%d resp=%.500s',
                $path, $code, $url, $this->costing, $pointCount, strlen($body), is_string($resp) ? $resp : '',
            ));
            return null;
        }
        return $resp;
    }
}
