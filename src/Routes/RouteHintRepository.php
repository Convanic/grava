<?php
declare(strict_types=1);

namespace App\Routes;

use App\Database\Db;
use App\Support\Uuid;
use PDO;

/**
 * Datenbank-Zugriff für Wegpunkt-Hinweise (`route_hints`).
 *
 * {@see sync()} spiegelt den aktuellen Upload: vorhandene Hinweise werden
 * per `(route_id, client_hint_uuid)` aktualisiert (Upsert), fehlende
 * gelöscht. Der `client_hint_uuid` wird deterministisch aus den
 * Hinweis-Eigenschaften gebildet, da der Client (noch) keinen mitsendet —
 * so erkennt ein Re-Upload denselben Hinweis idempotent wieder.
 */
final class RouteHintRepository
{
    /**
     * Synchronisiert die Hinweise einer Route auf den übergebenen Satz.
     * Erwartet, in der Upload-Transaktion aufgerufen zu werden.
     *
     * @param list<ParsedHint> $hints
     */
    public function sync(int $routeId, array $hints): void
    {
        $pdo = Db::pdo();

        $keep = [];
        $upsert = $pdo->prepare(
            'INSERT INTO route_hints
                (route_id, client_hint_uuid, reason_key, sentiment, label, note, lat, lon, recorded_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
                reason_key  = VALUES(reason_key),
                sentiment   = VALUES(sentiment),
                label       = VALUES(label),
                note        = VALUES(note),
                lat         = VALUES(lat),
                lon         = VALUES(lon),
                recorded_at = VALUES(recorded_at)'
        );

        foreach ($hints as $hint) {
            $uuid = self::deterministicUuid($routeId, $hint);
            // Doppelte client_hint_uuid innerhalb desselben Uploads (z. B.
            // zwei identische Hinweise am selben Punkt) nur einmal schreiben.
            if (isset($keep[$uuid])) {
                continue;
            }
            $keep[$uuid] = true;
            $upsert->execute([
                $routeId,
                $uuid,
                $hint->reasonKey,
                $hint->sentiment,
                $hint->label,
                $hint->note,
                $hint->lat,
                $hint->lon,
                $hint->recordedAt?->format('Y-m-d H:i:s.v'),
            ]);
        }

        // Hinweise, die im aktuellen Upload nicht mehr vorkommen, entfernen
        // (die Route spiegelt immer den letzten Upload).
        if ($keep === []) {
            $pdo->prepare('DELETE FROM route_hints WHERE route_id = ?')->execute([$routeId]);
            return;
        }
        $uuids = array_keys($keep);
        $ph = implode(',', array_fill(0, count($uuids), '?'));
        $del = $pdo->prepare(
            "DELETE FROM route_hints WHERE route_id = ? AND client_hint_uuid NOT IN ({$ph})"
        );
        $del->execute([$routeId, ...$uuids]);
    }

    /**
     * @return list<array<string,mixed>> Public-Form (für API/GeoJSON)
     */
    public function listForRoute(int $routeId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT reason_key, sentiment, label, note, lat, lon, recorded_at
               FROM route_hints
              WHERE route_id = ?
              ORDER BY recorded_at IS NULL, recorded_at ASC, id ASC'
        );
        $stmt->execute([$routeId]);
        return array_map([self::class, 'publicShape'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Wie {@see listForRoute()}, aber über die öffentliche Route-UUID —
     * ohne Owner-Check (Aufrufer hat die Sichtbarkeit bereits geprüft).
     *
     * @return list<array<string,mixed>>
     */
    public function listForPublicId(string $publicId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT h.reason_key, h.sentiment, h.label, h.note, h.lat, h.lon, h.recorded_at
               FROM route_hints h
               JOIN routes r ON r.id = h.route_id
              WHERE r.public_id = ?
              ORDER BY h.recorded_at IS NULL, h.recorded_at ASC, h.id ASC'
        );
        $stmt->execute([$publicId]);
        return array_map([self::class, 'publicShape'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private static function deterministicUuid(int $routeId, ParsedHint $hint): string
    {
        // Gerundet auf ~1 m, damit GPS-Jitter über Re-Uploads denselben
        // Hinweis trifft. recorded_at auf die Sekunde stabilisiert.
        $name = sprintf(
            'gravelexplorer:route-hint:%d:%s:%.5f:%.5f:%s',
            $routeId,
            $hint->reasonKey,
            $hint->lat,
            $hint->lon,
            $hint->recordedAt?->format('Y-m-d\TH:i:s') ?? '',
        );
        return Uuid::v5($name);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function publicShape(array $row): array
    {
        return [
            'reason_key'  => (string)$row['reason_key'],
            'sentiment'   => (string)$row['sentiment'],
            'label'       => (string)$row['label'],
            'note'        => $row['note'] === null ? null : (string)$row['note'],
            'lat'         => (float)$row['lat'],
            'lon'         => (float)$row['lon'],
            'recorded_at' => $row['recorded_at'] === null ? null : self::isoUtcMillis((string)$row['recorded_at']),
        ];
    }

    /**
     * 'YYYY-MM-DD HH:MM:SS(.fff)' → 'YYYY-MM-DDTHH:MM:SS.fffZ' (immer mit
     * Millisekunden, konsistent mit der iOS-Erwartung).
     */
    private static function isoUtcMillis(string $datetime): string
    {
        $iso = str_replace(' ', 'T', $datetime);
        if (!str_contains($iso, '.')) {
            $iso .= '.000';
        } else {
            // Auf genau 3 Nachkommastellen normalisieren.
            [$head, $frac] = explode('.', $iso, 2);
            $iso = $head . '.' . substr(str_pad($frac, 3, '0'), 0, 3);
        }
        return $iso . 'Z';
    }
}
