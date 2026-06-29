<?php
declare(strict_types=1);

namespace App\Game;

use PDO;

/**
 * Persistenz des Spiel-Ereignis-Stroms (`game_event`,
 * GAME_EVENTS_BACKEND.md Teil 1). Reines Lesen/Schreiben — die Erzeugung der
 * Ereignisse liegt im {@see GameEventRecorder}, die Zustellung im
 * {@see GameNotificationDispatcher}.
 */
final class GameEventRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Idempotentes Anlegen eines Ereignisses. Dedupliziert über den UNIQUE-Key
     * (type, user_id, edge_id, ridden_on) — ein erneuter Ingest/Recompute
     * desselben Tages feuert nicht doppelt.
     *
     * @param array<string,mixed>|null $payload
     * @return bool true, wenn eine neue Zeile entstand (sonst Duplikat ignoriert)
     */
    public function insertIgnore(
        string $type,
        int $userId,
        ?int $actorUserId,
        ?int $edgeId,
        ?int $rideId,
        ?int $crewId,
        ?string $riddenOn,
        ?array $payload = null,
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO game_event
                (type, user_id, actor_user_id, edge_id, ride_id, crew_id, ridden_on, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $type,
            $userId,
            $actorUserId,
            $edgeId,
            $rideId,
            $crewId,
            $riddenOn,
            $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null,
        ]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Noch nicht zugestellte Ereignisse der angegebenen Typen, sortiert für die
     * Gruppierung im Dispatcher (Empfänger → Typ → Alter).
     *
     * @param list<string> $types
     * @return list<array{id:int,type:string,user_id:int,actor_user_id:?int,edge_id:?int,created_at:string}>
     */
    public function pendingForDispatch(array $types): array
    {
        if ($types === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($types), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, type, user_id, actor_user_id, edge_id, created_at
               FROM game_event
              WHERE notified_at IS NULL AND type IN ($ph)
              ORDER BY user_id ASC, type ASC, created_at ASC, id ASC"
        );
        $stmt->execute(array_values($types));
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'            => (int)$r['id'],
                'type'          => (string)$r['type'],
                'user_id'       => (int)$r['user_id'],
                'actor_user_id' => $r['actor_user_id'] !== null ? (int)$r['actor_user_id'] : null,
                'edge_id'       => $r['edge_id'] !== null ? (int)$r['edge_id'] : null,
                'created_at'    => (string)$r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * Markiert Ereignisse als zugestellt (vom Push-Dispatcher verarbeitet).
     *
     * @param list<int> $eventIds
     */
    public function markNotified(array $eventIds, string $notifiedAt): void
    {
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        if ($eventIds === []) {
            return;
        }
        $ph = implode(',', array_fill(0, count($eventIds), '?'));
        $this->pdo->prepare(
            "UPDATE game_event SET notified_at = ? WHERE id IN ($ph) AND notified_at IS NULL"
        )->execute([$notifiedAt, ...$eventIds]);
    }

    /**
     * Hat $userId vor diesem Ingest bereits einen gültigen Pass auf $edgeId?
     * Grundlage der „neuer Fahrer"-Erkennung für pioneer_joined. Schließt die
     * Pässe der laufenden Route aus, damit der gerade eingetragene Pass die
     * Erstbefahrung-Erkennung nicht selbst verfälscht.
     */
    public function userHadPriorPass(int $userId, int $edgeId, int $exceptRouteId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM game_edge_pass
              WHERE user_id = ? AND edge_id = ? AND route_id <> ? AND invalidated_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$userId, $edgeId, $exceptRouteId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Gab es vor dieser Route bereits einen gültigen Pass (von irgendwem) auf
     * der Kante? false ⇒ diese Route hat die Kante erschlossen (edge_new).
     */
    public function edgeHadPriorPass(int $edgeId, int $exceptRouteId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM game_edge_pass
              WHERE edge_id = ? AND route_id <> ? AND invalidated_at IS NULL
              LIMIT 1'
        );
        $stmt->execute([$edgeId, $exceptRouteId]);
        return $stmt->fetchColumn() !== false;
    }
}
