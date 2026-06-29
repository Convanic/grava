<?php
declare(strict_types=1);

namespace App\Game;

use App\Engagement\NotificationService;
use App\Privacy\PrivacyZoneRepository;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Zustellung des Spiel-Ereignis-Stroms als Inbox-Mitteilung + APNs
 * (GAME_PUSH_BACKEND.md, Phase B). Läuft asynchron per Cron
 * (`game:notify-dispatch`), entkoppelt vom Ingest.
 *
 * Bündelung über ein Zeitfenster: pro (Empfänger, Typ) wird verarbeitet, sobald
 * entweder die Digest-Schwelle erreicht ist (sofort) ODER das Fenster abgelaufen
 * ist (auch wenige Ereignisse werden dann einzeln zugestellt). Ab der Schwelle
 * entsteht EINE Digest-Mitteilung (count=N, actor=null, edge_id=null), sonst je
 * Ereignis eine Einzel-Mitteilung mit Deep-Link (edge_id, Heimatzone-maskiert).
 */
final class GameNotificationDispatcher
{
    /** Push-relevante Ereignistypen. edge_new wird bewusst NICHT zugestellt. */
    private const PUSH_EVENT_TYPES = ['edge_taken', 'edge_reclaimed', 'record_beaten', 'pioneer_joined'];

    public function __construct(
        private readonly GameEventRepository $events,
        private readonly NotificationService $notifications,
        private readonly GameRepository $repo,
        private readonly PrivacyZoneRepository $zones,
        private readonly GameConfig $config,
    ) {}

    /**
     * Verarbeitet fällige Ereignisse. Liefert die Anzahl erzeugter Mitteilungen.
     */
    public function dispatch(DateTimeImmutable $now): int
    {
        $threshold = max(1, $this->config->int('push_game_digest_threshold'));
        $windowMin = max(0, $this->config->int('push_game_digest_window_min'));

        $pending = $this->events->pendingForDispatch(self::PUSH_EVENT_TYPES);
        if ($pending === []) {
            return 0;
        }

        // Gruppieren nach (Empfänger, Typ).
        /** @var array<string,list<array{id:int,type:string,user_id:int,actor_user_id:?int,edge_id:?int,created_at:string}>> $groups */
        $groups = [];
        foreach ($pending as $row) {
            $groups[$row['user_id'] . '|' . $row['type']][] = $row;
        }

        $notifiedAt = $now->format('Y-m-d H:i:s.v');
        $sent = 0;
        $processedIds = [];

        foreach ($groups as $rows) {
            $count = count($rows);
            $oldest = $this->parse($rows[0]['created_at']); // pending ist nach created_at sortiert
            $ageMin = ($now->getTimestamp() - $oldest->getTimestamp()) / 60.0;

            $ready = $count >= $threshold || $ageMin >= $windowMin;
            if (!$ready) {
                continue; // Fenster läuft noch — nächster Lauf
            }

            $userId = $rows[0]['user_id'];
            $type   = $rows[0]['type'];

            if ($count >= $threshold) {
                // Digest: eine Mitteilung, kein einzelner Auslöser, kein Deep-Link.
                $this->notifications->notifyGame($userId, null, $type, null, $count);
                $sent++;
            } else {
                // Einzel-Mitteilungen mit Kanten-Deep-Link (Heimatzone-maskiert).
                foreach ($rows as $row) {
                    $edgeId = $row['edge_id'];
                    if ($edgeId !== null && $this->isMasked($userId, $edgeId)) {
                        $edgeId = null;
                    }
                    $this->notifications->notifyGame($userId, $row['actor_user_id'], $type, $edgeId, 1);
                    $sent++;
                }
            }

            foreach ($rows as $row) {
                $processedIds[] = $row['id'];
            }
        }

        $this->events->markNotified($processedIds, $notifiedAt);
        return $sent;
    }

    /**
     * Liegt die Kante in der aktiven Heimatzone des Empfängers? Dann kein
     * Deep-Link (AC6). Ohne Zone/Geometrie nicht maskiert.
     */
    private function isMasked(int $userId, int $edgeId): bool
    {
        $zone = $this->zones->enabledZoneForUser($userId);
        if ($zone === null) {
            return false;
        }
        $edge = $this->repo->edgeById($edgeId);
        $geom = $edge !== null ? json_decode((string)($edge['geom_geojson'] ?? ''), true) : null;
        if (!is_array($geom) || count($geom) < 1) {
            return false;
        }
        return $zone->intersectsPolyline($geom);
    }

    private function parse(string $dt): DateTimeImmutable
    {
        return new DateTimeImmutable($dt, new DateTimeZone('UTC'));
    }
}
