<?php
declare(strict_types=1);

namespace App\Game;

use App\Engagement\NotificationService;

/**
 * Welle 2 (backend/PUSH_BACKEND.md §4): erkennt Besitzwechsel und
 * benachrichtigt den/die vorherige(n) Besitzer:in („Dein Revier wurde
 * übernommen"). Aggregiert je Upload: pro verlierender Person genau EINE
 * Notification (+ ein Push) — egal wie viele Kanten gleichzeitig kippen.
 *
 * Bei Crew-Besitz werden alle Crew-Mitglieder benachrichtigt. Auslöser
 * (Actor) ist die hochladende Person; die Self-Notification-Regel von
 * {@see NotificationService} verhindert Eigen-Benachrichtigung.
 */
final class TerritoryTakeoverNotifier
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly ?NotificationService $notifications = null,
    ) {}

    /**
     * @param array<int,?int> $prevOwners [edgeId => owner_claimant_id vor Recompute]
     * @param array<int,?int> $newOwners  [edgeId => owner_claimant_id nach Recompute]
     * @return int Anzahl benachrichtigter Personen
     */
    public function notify(array $prevOwners, array $newOwners, int $actorUserId): int
    {
        if ($this->notifications === null) {
            return 0;
        }

        // Verlierer-Claimants: Kante hatte vorher einen Besitzer, hat jetzt
        // einen ANDEREN (echten) Besitzer.
        $loserClaimants = [];
        foreach ($prevOwners as $edgeId => $prev) {
            $new = $newOwners[$edgeId] ?? null;
            if ($prev !== null && $new !== null && $prev !== $new) {
                $loserClaimants[$prev] = true;
            }
        }
        if ($loserClaimants === []) {
            return 0;
        }

        // Claimants → reale Empfänger (Rider = 1 User, Crew = alle Mitglieder),
        // dedupliziert; die auslösende Person ausschließen.
        $recipients = [];
        foreach (array_keys($loserClaimants) as $cid) {
            foreach ($this->repo->usersForClaimant((int)$cid) as $uid) {
                $recipients[$uid] = true;
            }
        }
        unset($recipients[$actorUserId]);

        $count = 0;
        foreach (array_keys($recipients) as $uid) {
            $this->notifications->notify((int)$uid, $actorUserId, 'territory_taken');
            $count++;
        }
        return $count;
    }
}
