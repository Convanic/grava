<?php
declare(strict_types=1);

namespace App\Game\Rush;

use PDO;

/**
 * CRUD + Lese-Aggregationen für Rushes (game_rush) und RSVPs (game_rush_rsvp).
 * Keine Spiellogik — die liegt in {@see RushService}. Auto-Tag/Recompute-Queries
 * gegen game_edge_pass leben in {@see \App\Game\GameRepository} (gemeinsamer
 * Pfad mit Ingestion/Recalculator).
 */
final class RushRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function create(
        int $crewId,
        int $createdBy,
        string $startAt,
        string $endAt,
        float $multiplier,
        ?float $meetupLat,
        ?float $meetupLon,
    ): int {
        $this->pdo->prepare(
            'INSERT INTO game_rush
                (crew_id, created_by, start_at, end_at, multiplier, meetup_lat, meetup_lon, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, "planned")'
        )->execute([$crewId, $createdBy, $startAt, $endAt, $multiplier, $meetupLat, $meetupLon]);
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string,mixed>|null Roh-Zeile inkl. Captain-Handle (created_by). */
    public function byId(int $rushId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.public_handle AS created_by_handle, cr.claimant_id AS crew_claimant_id
               FROM game_rush r
               LEFT JOIN users u ON u.id = r.created_by
               JOIN game_crew cr ON cr.id = r.crew_id
              WHERE r.id = ?'
        );
        $stmt->execute([$rushId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * Der eine relevante Rush der Crew (§5.2): laufender ('active') zuerst,
     * sonst der nächste 'planned' nach Startzeit. Sonst null.
     *
     * @return array<string,mixed>|null
     */
    public function activeOrNextForCrew(int $crewId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.public_handle AS created_by_handle, cr.claimant_id AS crew_claimant_id
               FROM game_rush r
               LEFT JOIN users u ON u.id = r.created_by
               JOIN game_crew cr ON cr.id = r.crew_id
              WHERE r.crew_id = ? AND r.status IN ("active","planned")
              ORDER BY (r.status = "active") DESC, r.start_at ASC
              LIMIT 1'
        );
        $stmt->execute([$crewId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /**
     * Überlappt ein nicht-abgeschlossener Rush (planned/active) der Crew das
     * Fenster [$startAt,$endAt]? (§5.1 → 409).
     */
    public function overlapExists(int $crewId, string $startAt, string $endAt): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM game_rush
              WHERE crew_id = ? AND status IN ("planned","active")
                AND start_at <= ? AND end_at >= ?
              LIMIT 1'
        );
        // Overlap [s,e] ∩ [S,E] ≠ ∅  ⇔  s <= E AND e >= S.
        $stmt->execute([$crewId, $endAt, $startAt]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Startzeit des jüngsten nicht abgebrochenen Rushes der Crew (Cooldown §5.1).
     * Cancelled Rushes "zählen" nicht.
     */
    public function lastRushStart(int $crewId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT MAX(start_at) FROM game_rush
              WHERE crew_id = ? AND status <> "cancelled"'
        );
        $stmt->execute([$crewId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    public function setStatus(int $rushId, string $status): void
    {
        $this->pdo->prepare('UPDATE game_rush SET status = ? WHERE id = ?')
            ->execute([$status, $rushId]);
    }

    /** Offene Rushes (planned/active) einer Crew — für den Lazy-Tick (§4). */
    public function openForCrew(int $crewId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, start_at, end_at FROM game_rush
              WHERE crew_id = ? AND status IN ("planned","active")'
        );
        $stmt->execute([$crewId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Alle offenen Rushes, deren Status sich relativ zu $now ändern könnte
     * (für den Cron-Tick game:rush-tick, §4).
     *
     * @return list<array{id:int,crew_id:int,status:string,start_at:string,end_at:string}>
     */
    public function ticklableRushes(string $now): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, crew_id, status, start_at, end_at FROM game_rush
              WHERE status IN ("planned","active")
                AND (start_at <= ? OR end_at <= ?)
              ORDER BY id'
        );
        $stmt->execute([$now, $now]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'id'       => (int)$r['id'],
                'crew_id'  => (int)$r['crew_id'],
                'status'   => (string)$r['status'],
                'start_at' => (string)$r['start_at'],
                'end_at'   => (string)$r['end_at'],
            ];
        }
        return $out;
    }

    // ---- RSVP (reine Koordination) -------------------------------------

    public function rsvpUpsert(int $rushId, int $userId, string $state): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_rush_rsvp (rush_id, user_id, state, responded_at)
             VALUES (?, ?, ?, CURRENT_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE state = VALUES(state), responded_at = VALUES(responded_at)'
        )->execute([$rushId, $userId, $state]);
    }

    /** @return list<array{handle:?string,state:string}> */
    public function rsvps(int $rushId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.public_handle AS handle, v.state
               FROM game_rush_rsvp v
               JOIN users u ON u.id = v.user_id
              WHERE v.rush_id = ?
              ORDER BY v.responded_at ASC, v.user_id ASC'
        );
        $stmt->execute([$rushId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'handle' => $r['handle'] !== null ? (string)$r['handle'] : null,
                'state'  => (string)$r['state'],
            ];
        }
        return $out;
    }

    public function confirmedCount(int $rushId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM game_rush_rsvp WHERE rush_id = ? AND state = "yes"'
        );
        $stmt->execute([$rushId]);
        return (int)$stmt->fetchColumn();
    }

    // ---- Live-/Ergebnis-Kennzahlen (§5.5) ------------------------------

    /** distinct Mitglieder mit echtem getaggtem Pass (participants_ridden / Gate). */
    public function distinctRidden(int $rushId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT user_id) FROM game_edge_pass
              WHERE rush_id = ? AND invalidated_at IS NULL'
        );
        $stmt->execute([$rushId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Übernommene Kanten: distinct getaggte Kanten, die JETZT der Crew gehören
     * (owner = crew-claimant). Live-Annäherung; final nach 'completed' (§5.5).
     */
    public function edgesCaptured(int $rushId, int $crewClaimantId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT p.edge_id)
               FROM game_edge_pass p
               JOIN game_edge e ON e.id = p.edge_id
              WHERE p.rush_id = ? AND p.invalidated_at IS NULL
                AND e.owner_claimant_id = ?'
        );
        $stmt->execute([$rushId, $crewClaimantId]);
        return (int)$stmt->fetchColumn();
    }
}
