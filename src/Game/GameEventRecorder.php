<?php
declare(strict_types=1);

namespace App\Game;

/**
 * Materialisiert Spiel-Ereignisse (GAME_EVENTS_BACKEND.md Teil 1) aus den
 * Zustandsänderungen einer Ingestion in den gemeinsamen Strom `game_event`.
 * Nachfolger des {@see TerritoryTakeoverNotifier}: statt direkt Notifications
 * zu schreiben, entsteht hier der Ereignis-Strom — die Zustellung (Inbox +
 * APNs, Digest) übernimmt der {@see GameNotificationDispatcher} (Phase B).
 *
 * Wird innerhalb der Ingest-Transaktion (nach dem Recompute) aufgerufen, damit
 * Pässe und Ereignisse atomar zusammen committen. Idempotent über den
 * UNIQUE-Key von `game_event` — Re-Ingest desselben Tages feuert nicht doppelt.
 *
 * Heute emittiert: edge_taken (Priorität), pioneer_joined, edge_new.
 * Vorbereitet (Emission später): edge_lost, edge_reclaimed, record_beaten.
 */
final class GameEventRecorder
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameEventRepository $events,
    ) {}

    /**
     * @param array<int,?int> $prevOwners [edgeId => owner_claimant_id vor Recompute]
     * @param array<int,?int> $newOwners  [edgeId => owner_claimant_id nach Recompute]
     * @param list<int>       $edgeIds    berührte Kanten
     * @return int Anzahl geschriebener Ereignisse (neue Zeilen)
     */
    public function record(
        array $prevOwners,
        array $newOwners,
        array $edgeIds,
        int $actorUserId,
        int $rideId,
        string $riddenOn,
    ): int {
        $actorClaimant = $this->repo->effectiveClaimantId($actorUserId);
        $written = 0;

        foreach ($edgeIds as $edgeId) {
            $prev = $prevOwners[$edgeId] ?? null;
            $new  = $newOwners[$edgeId] ?? null;

            // edge_taken: Kante hatte vorher einen Besitzer, jetzt einen anderen
            // (echten) — der/die bisherige(n) Besitzer:in verliert.
            $takenRecipients = [];
            if ($prev !== null && $new !== null && $prev !== $new) {
                foreach ($this->recipientsExceptActor($prev, $actorUserId) as $uid) {
                    $takenRecipients[$uid] = true;
                    if ($this->events->insertIgnore(
                        'edge_taken', $uid, $actorUserId, $edgeId, $rideId, null, $riddenOn,
                    )) {
                        $written++;
                    }
                }
            }

            // edge_new: diese Route hat die Kante erschlossen (kein früherer
            // Pass). Empfänger = der Fahrer selbst (für Recap/Challenge, kein Push).
            if (!$this->events->edgeHadPriorPass($edgeId, $rideId)) {
                if ($this->events->insertIgnore(
                    'edge_new', $actorUserId, $actorUserId, $edgeId, $rideId, null, $riddenOn,
                )) {
                    $written++;
                }
                continue; // brandneue Kante → niemand kann hier „Pionier-Besuch" sein
            }

            // pioneer_joined: ein NEUER Fahrer (dieser Upload) fährt eine bereits
            // von jemand anderem erschlossene Kante → der Erstbefahrer wird benachrichtigt.
            $edge = $this->repo->edgeById($edgeId);
            $discoverer = $edge !== null && $edge['discoverer_claimant_id'] !== null
                ? (int)$edge['discoverer_claimant_id']
                : null;
            if ($discoverer !== null
                && $discoverer !== $actorClaimant
                && !$this->events->userHadPriorPass($actorUserId, $edgeId, $rideId)
            ) {
                foreach ($this->recipientsExceptActor($discoverer, $actorUserId) as $uid) {
                    // Wer für diese Kante schon edge_taken bekommt, soll nicht
                    // zusätzlich pioneer_joined erhalten (kein Doppel-Ping).
                    if (isset($takenRecipients[$uid])) {
                        continue;
                    }
                    if ($this->events->insertIgnore(
                        'pioneer_joined', $uid, $actorUserId, $edgeId, $rideId, null, $riddenOn,
                    )) {
                        $written++;
                    }
                }
            }
        }

        return $written;
    }

    /**
     * Reale Empfänger hinter einem Claimant (Rider = 1 User, Crew = alle
     * Mitglieder), die auslösende Person ausgeschlossen.
     *
     * @return list<int>
     */
    private function recipientsExceptActor(int $claimantId, int $actorUserId): array
    {
        $out = [];
        foreach ($this->repo->usersForClaimant($claimantId) as $uid) {
            if ($uid !== $actorUserId) {
                $out[] = $uid;
            }
        }
        return $out;
    }
}
