<?php
declare(strict_types=1);

namespace App\Game\Crew;

use PDO;

/**
 * CRUD für Crews (game_crew) und Mitgliedschaften (game_crew_member) inkl.
 * Group-Claimant-Verwaltung (game_claimant type='group'). Keine Spiellogik —
 * die liegt in CrewService.
 */
final class CrewRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** Legt einen neutralen Group-Claimant an und liefert dessen id. */
    public function createGroupClaimant(): int
    {
        $this->pdo->prepare(
            'INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)'
        )->execute();
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteClaimant(int $claimantId): void
    {
        $this->pdo->prepare('DELETE FROM game_claimant WHERE id = ?')->execute([$claimantId]);
    }

    public function createCrew(int $claimantId, string $name, string $slug, int $ownerUserId, string $joinCode): int
    {
        $this->pdo->prepare(
            'INSERT INTO game_crew (claimant_id, name, slug, owner_user_id, join_code)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$claimantId, $name, $slug, $ownerUserId, $joinCode]);
        return (int)$this->pdo->lastInsertId();
    }

    /** game_crew ON DELETE CASCADE räumt die Mitglieder mit. */
    public function deleteCrew(int $crewId): void
    {
        $this->pdo->prepare('DELETE FROM game_crew WHERE id = ?')->execute([$crewId]);
    }

    /** @return array<string,mixed>|null */
    public function crewById(int $crewId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_crew WHERE id = ?');
        $stmt->execute([$crewId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    public function crewBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_crew WHERE slug = ?');
        $stmt->execute([$slug]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    public function crewByJoinCode(string $joinCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM game_crew WHERE join_code = ?');
        $stmt->execute([$joinCode]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @return array{user_id:int,crew_id:int,role:string}|null Mitgliedschaft des Users. */
    public function membershipOf(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id, crew_id, role FROM game_crew_member WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r === false) {
            return null;
        }
        return [
            'user_id' => (int)$r['user_id'],
            'crew_id' => (int)$r['crew_id'],
            'role'    => (string)$r['role'],
        ];
    }

    public function memberCount(int $crewId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM game_crew_member WHERE crew_id = ?');
        $stmt->execute([$crewId]);
        return (int)$stmt->fetchColumn();
    }

    /** @return list<array{user_id:int,role:string,handle:?string,name:?string,joined_at:string}> */
    public function members(int $crewId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.user_id, m.role, m.joined_at,
                    u.public_handle AS handle, u.display_name AS name
               FROM game_crew_member m
               JOIN users u ON u.id = m.user_id
              WHERE m.crew_id = ?
              ORDER BY (m.role = "captain") DESC, m.joined_at ASC, m.user_id ASC'
        );
        $stmt->execute([$crewId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'user_id'   => (int)$r['user_id'],
                'role'      => (string)$r['role'],
                'handle'    => $r['handle'] !== null ? (string)$r['handle'] : null,
                'name'      => $r['name'] !== null ? (string)$r['name'] : null,
                'joined_at' => (string)$r['joined_at'],
            ];
        }
        return $out;
    }

    public function addMember(int $userId, int $crewId, string $role): void
    {
        $this->pdo->prepare(
            'INSERT INTO game_crew_member (user_id, crew_id, role) VALUES (?, ?, ?)'
        )->execute([$userId, $crewId, $role]);
    }

    public function removeMember(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM game_crew_member WHERE user_id = ?')->execute([$userId]);
    }

    public function setRole(int $userId, string $role): void
    {
        $this->pdo->prepare('UPDATE game_crew_member SET role = ? WHERE user_id = ?')
            ->execute([$role, $userId]);
    }

    public function setOwnerUser(int $crewId, int $ownerUserId): void
    {
        $this->pdo->prepare('UPDATE game_crew SET owner_user_id = ? WHERE id = ?')
            ->execute([$ownerUserId, $crewId]);
    }

    // ----------------------------------------------------------------
    // Crew-Logo (GAME_CREW_LOGO_BACKEND.md) — Spiegel des Avatar-Mechanismus
    // ----------------------------------------------------------------

    /** Setzt logo_path + logo_updated_at (Upload/Replace). */
    public function setLogo(int $crewId, string $relPath, string $updatedAt): void
    {
        $this->pdo->prepare('UPDATE game_crew SET logo_path = ?, logo_updated_at = ? WHERE id = ?')
            ->execute([$relPath, $updatedAt, $crewId]);
    }

    /** Entfernt logo_path + logo_updated_at (auf NULL). */
    public function clearLogo(int $crewId): void
    {
        $this->pdo->prepare('UPDATE game_crew SET logo_path = NULL, logo_updated_at = NULL WHERE id = ?')
            ->execute([$crewId]);
    }

    // ----------------------------------------------------------------
    // Captain-Invariante / Self-Healing (GAME_RUSH_BACKEND.md §12)
    // ----------------------------------------------------------------

    /**
     * Hat die Crew aktuell einen *gültigen* Captain (role=captain UND aktiver
     * User)? Eine Captain-Zeile, die auf einen gelöschten Account zeigt, gilt
     * NICHT als Captain — sonst bliebe die Crew in der Sackgasse (§12.3).
     */
    public function hasActiveCaptain(int $crewId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM game_crew_member m JOIN users u ON u.id = m.user_id
              WHERE m.crew_id = ? AND m.role = "captain" AND u.status = "active" LIMIT 1'
        );
        $stmt->execute([$crewId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Handle des aktiven Captains (oder null, wenn captain-los). */
    public function captainHandle(int $crewId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.public_handle FROM game_crew_member m JOIN users u ON u.id = m.user_id
              WHERE m.crew_id = ? AND m.role = "captain" AND u.status = "active" LIMIT 1'
        );
        $stmt->execute([$crewId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (string)$v;
    }

    /**
     * Mitglied der Crew per öffentlichem Handle (nur aktive User).
     * @return array{user_id:int,role:string}|null
     */
    public function memberByHandle(int $crewId, string $handle): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.user_id, m.role FROM game_crew_member m JOIN users u ON u.id = m.user_id
              WHERE m.crew_id = ? AND u.public_handle = ? AND u.status = "active" LIMIT 1'
        );
        $stmt->execute([$crewId, $handle]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : ['user_id' => (int)$r['user_id'], 'role' => (string)$r['role']];
    }

    /**
     * Ältestes Mitglied (kleinste joined_at, dann user_id), optional exkl. eines
     * Users. $activeOnly bevorzugt aktive Accounts für die Notbesetzung.
     */
    public function oldestMember(int $crewId, ?int $exceptUserId = null, bool $activeOnly = false): ?int
    {
        $sql = 'SELECT m.user_id FROM game_crew_member m';
        $sql .= $activeOnly ? ' JOIN users u ON u.id = m.user_id' : '';
        $sql .= ' WHERE m.crew_id = ?';
        $params = [$crewId];
        if ($activeOnly) {
            $sql .= ' AND u.status = "active"';
        }
        if ($exceptUserId !== null) {
            $sql .= ' AND m.user_id <> ?';
            $params[] = $exceptUserId;
        }
        $sql .= ' ORDER BY m.joined_at ASC, m.user_id ASC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (int)$v;
    }

    /** Setzt alle Captain-Zeilen der Crew auf 'member' (vor einer Neubesetzung). */
    public function clearCaptains(int $crewId): void
    {
        $this->pdo->prepare('UPDATE game_crew_member SET role = "member" WHERE crew_id = ? AND role = "captain"')
            ->execute([$crewId]);
    }

    /**
     * Nicht-leere Crews ohne gültigen (aktiven) Captain — Altbestands-Datencheck
     * (§12.1). Liefert die Crews, die geheilt werden müssen.
     *
     * @return list<array{crew_id:int,slug:string,name:string,members:int}>
     */
    public function captainlessCrews(): array
    {
        $rows = $this->pdo->query(
            'SELECT cr.id AS crew_id, cr.slug, cr.name,
                    (SELECT COUNT(*) FROM game_crew_member m WHERE m.crew_id = cr.id) AS members
               FROM game_crew cr
              WHERE (SELECT COUNT(*) FROM game_crew_member m WHERE m.crew_id = cr.id) > 0
                AND NOT EXISTS (
                    SELECT 1 FROM game_crew_member cm JOIN users u ON u.id = cm.user_id
                     WHERE cm.crew_id = cr.id AND cm.role = "captain" AND u.status = "active")
              ORDER BY cr.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'crew_id' => (int)$r['crew_id'],
                'slug'    => (string)$r['slug'],
                'name'    => (string)$r['name'],
                'members' => (int)$r['members'],
            ];
        }
        return $out;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM game_crew WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetchColumn() !== false;
    }

    public function joinCodeExists(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM game_crew WHERE join_code = ?');
        $stmt->execute([$code]);
        return $stmt->fetchColumn() !== false;
    }

    // ----------------------------------------------------------------
    // Rangliste (Leaderboard) — reine Lese-Aggregationen
    // ----------------------------------------------------------------

    /**
     * Aktuell von der Crew gehaltene Kanten.
     * @return array<int,float> edge_id => length_m
     */
    public function crewOwnedEdges(int $claimantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, length_m FROM game_edge WHERE owner_claimant_id = ?'
        );
        $stmt->execute([$claimantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['id']] = (float)$r['length_m'];
        }
        return $out;
    }

    /**
     * Gültige Pässe der angegebenen User auf den angegebenen Kanten im Fenster
     * (seit $sinceDate). Invalidierte ausgeschlossen.
     *
     * @param list<int> $edgeIds
     * @param list<int> $userIds
     * @return list<array{edge_id:int,user_id:int,ridden_at:string}>
     */
    public function passesOnEdgesForUsers(array $edgeIds, array $userIds, string $sinceDate): array
    {
        if ($edgeIds === [] || $userIds === []) {
            return [];
        }
        $ePh = implode(',', array_fill(0, count($edgeIds), '?'));
        $uPh = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT edge_id, user_id, ridden_at FROM game_edge_pass
              WHERE edge_id IN ($ePh) AND user_id IN ($uPh)
                AND invalidated_at IS NULL AND ridden_on >= ?"
        );
        $stmt->execute([...array_values($edgeIds), ...array_values($userIds), $sinceDate]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'edge_id'   => (int)$r['edge_id'],
                'user_id'   => (int)$r['user_id'],
                'ridden_at' => (string)$r['ridden_at'],
            ];
        }
        return $out;
    }

    /**
     * Aktivität der User im Fenster (seit $sinceDate), unabhängig vom Besitz:
     * Distanz = Σ Kantenlänge über gültige Pässe, Fahrten = distinct route_id.
     *
     * @param list<int> $userIds
     * @return array<int,array{rides:int,distance:float}>
     */
    public function activityForUsers(array $userIds, string $sinceDate): array
    {
        if ($userIds === []) {
            return [];
        }
        $uPh = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT p.user_id,
                    COUNT(DISTINCT p.route_id)      AS rides,
                    COALESCE(SUM(e.length_m), 0)    AS distance
               FROM game_edge_pass p
               JOIN game_edge e ON e.id = p.edge_id
              WHERE p.user_id IN ($uPh) AND p.invalidated_at IS NULL AND p.ridden_on >= ?
              GROUP BY p.user_id"
        );
        $stmt->execute([...array_values($userIds), $sinceDate]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['user_id']] = [
                'rides'    => (int)$r['rides'],
                'distance' => (float)$r['distance'],
            ];
        }
        return $out;
    }
}
