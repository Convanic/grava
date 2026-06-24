<?php
declare(strict_types=1);

namespace App\Push;

use App\Database\Db;
use PDO;

/**
 * Baut die APNs-Nutzlast zu einer gerade erzeugten Notification und
 * verteilt sie an alle Geräte des Empfängers (siehe
 * backend/PUSH_BACKEND.md §3).
 *
 * Aufruf erfolgt synchron aus {@see \App\Engagement\NotificationService}
 * — aber strikt best effort: jeder Fehler wird geschluckt/geloggt, damit
 * die auslösende Aktion (Follow/Like/Comment/…) nie scheitert.
 */
final class PushService
{
    public function __construct(
        private readonly PushDeviceRepository $devices,
        private readonly ApnsTransport $transport,
    ) {}

    public function dispatch(
        int $notificationId,
        int $recipientId,
        int $actorId,
        string $type,
        ?string $subjectType = null,
        ?int $subjectId = null,
    ): void {
        try {
            $devices = $this->devices->forUser($recipientId);
            if ($devices === []) {
                return;
            }

            $actor = $this->loadActor($actorId);
            $routePublicId = ($subjectType === 'route' && $subjectId !== null)
                ? $this->routePublicId($subjectId)
                : null;
            $badge = $this->unreadCount($recipientId);

            $payload = $this->buildPayload($notificationId, $type, $actor, $routePublicId, $badge);
            // Rush-Deep-Link (rush/{id}): subject_type='rush' trägt die Rush-ID
            // ins Payload, damit iOS direkt auf RushView springen kann.
            if ($subjectType === 'rush' && $subjectId !== null) {
                $payload['rush_id'] = (string)$subjectId;
            }
            // Collapse je (Typ, Notification) — verhindert Duplikate bei Retries.
            $collapseId = substr($type . '-' . $notificationId, 0, 64);

            foreach ($devices as $d) {
                $status = $this->transport->send($d['environment'], $d['token'], $payload, $collapseId);
                // 410 = Unregistered → Token ist endgültig ungültig, entfernen.
                if ($status === 410) {
                    $this->devices->deleteByToken($d['token']);
                }
            }
        } catch (\Throwable $e) {
            error_log('PushService::dispatch: ' . $e->getMessage());
        }
    }

    /**
     * @param array{handle:?string,name:?string} $actor
     * @return array<string,mixed>
     */
    private function buildPayload(
        int $notificationId,
        string $type,
        array $actor,
        ?string $routePublicId,
        int $badge,
    ): array {
        $actorLabel = $actor['name'] ?? ($actor['handle'] !== null ? '@' . $actor['handle'] : 'Jemand');

        [$title, $body] = match ($type) {
            'follow'          => ['Neuer Follower', $actorLabel . ' folgt dir jetzt.'],
            'like'            => ['Gefällt', $actorLabel . ' gefällt deine Route.'],
            'comment'         => ['Neuer Kommentar', $actorLabel . ' hat deine Route kommentiert.'],
            'territory_taken' => ['Revier übernommen', 'Dein Revier wurde übernommen.'],
            'crew_invite'     => ['Crew-Einladung', $actorLabel . ' hat dich in eine Crew eingeladen.'],
            'rush_invite'     => ['Rush angesetzt', $actorLabel . ' hat einen Rush angesetzt — fahrt jetzt zusammen!'],
            'rush_reminder'   => ['Rush startet bald', 'Euer Rush startet gleich.'],
            'rush_result'     => ['Rush beendet', 'Das Ergebnis eures Rushes steht fest.'],
            default           => ['GRAVA', $actorLabel . ' hat eine Aktion ausgeführt.'],
        };

        $payload = [
            'aps' => [
                'alert' => ['title' => $title, 'body' => $body],
                'sound' => 'default',
                'badge' => $badge,
            ],
            'type'            => $type,
            'notification_id' => (string)$notificationId,
        ];
        if ($routePublicId !== null) {
            $payload['route_id'] = $routePublicId;
        }
        if ($actor['handle'] !== null) {
            $payload['handle'] = $actor['handle'];
        }
        return $payload;
    }

    /** @return array{handle:?string,name:?string} */
    private function loadActor(int $actorId): array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT public_handle, display_name FROM users WHERE id = ?'
        );
        $stmt->execute([$actorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'handle' => isset($row['public_handle']) && $row['public_handle'] !== null ? (string)$row['public_handle'] : null,
            'name'   => isset($row['display_name']) && $row['display_name'] !== null ? (string)$row['display_name'] : null,
        ];
    }

    private function routePublicId(int $routeId): ?string
    {
        $stmt = Db::pdo()->prepare(
            'SELECT public_id FROM routes WHERE id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$routeId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    private function unreadCount(int $userId): int
    {
        $stmt = Db::pdo()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'
        );
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }
}
