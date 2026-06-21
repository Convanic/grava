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
}
