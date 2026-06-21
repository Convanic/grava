<?php
declare(strict_types=1);

namespace App\Game\Faction;

use PDO;

/**
 * Lese-/Schreibzugriff auf Fraktionen (game_faction) und die
 * Crew-Fraktionsbindung (game_crew.faction_id/faction_joined_at).
 *
 * Fraktionen besitzen KEINE Kanten — sie sind eine reine Aggregations-/
 * Meta-Ebene. Besitz/Wert bleiben bei der Crew (GAME_STAGE3_BACKEND.md §1).
 */
final class FactionRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array{id:int,key:string,name:string,color:string}|null */
    public function byKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, key_slug, name, color_hex FROM game_faction WHERE key_slug = ?');
        $stmt->execute([$key]);
        return self::shape($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /** @return array{id:int,key:string,name:string,color:string}|null */
    public function byId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, key_slug, name, color_hex FROM game_faction WHERE id = ?');
        $stmt->execute([$id]);
        return self::shape($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id:int,key:string,name:string,color:string}> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT id, key_slug, name, color_hex FROM game_faction ORDER BY id')
            ->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn($r) => self::shape($r), $rows);
    }

    /** Setzt Fraktion + Wechselzeitpunkt einer Crew. faction_id=null → neutral. */
    public function setCrewFaction(int $crewId, ?int $factionId, ?string $joinedAt): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE game_crew SET faction_id = ?, faction_joined_at = ? WHERE id = ?'
        );
        $stmt->bindValue(1, $factionId, $factionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(2, $joinedAt, $joinedAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(3, $crewId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Crews + distinct Mitglieder je Fraktion (für die Standings).
     *
     * @return array<int,array{crews:int,members:int}> [faction_id => …]
     */
    public function crewMemberCounts(): array
    {
        $rows = $this->pdo->query(
            'SELECT cr.faction_id AS fid,
                    COUNT(DISTINCT cr.id)      AS crews,
                    COUNT(DISTINCT m.user_id)  AS members
               FROM game_crew cr
               LEFT JOIN game_crew_member m ON m.crew_id = cr.id
              WHERE cr.faction_id IS NOT NULL
              GROUP BY cr.faction_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['fid']] = ['crews' => (int)$r['crews'], 'members' => (int)$r['members']];
        }
        return $out;
    }

    /**
     * Fraktions-gebundene Kanten (Besitzer = Crew mit Fraktion), optional auf
     * eine BBox eingeschränkt. Liefert Länge + Mittelpunkt (aus der BBox der
     * Kante) + Fraktion — Grundlage für Meta-Karte (Zellen) und Standings.
     *
     * @return list<array{length_m:float,lat:float,lon:float,key:string,color:string,faction_id:int}>
     */
    public function edgesWithFaction(
        ?float $minLon = null,
        ?float $minLat = null,
        ?float $maxLon = null,
        ?float $maxLat = null,
    ): array {
        $sql = 'SELECT e.length_m,
                       (e.min_lat + e.max_lat) / 2 AS lat,
                       (e.min_lon + e.max_lon) / 2 AS lon,
                       cr.faction_id AS faction_id,
                       f.key_slug AS faction_key, f.color_hex AS faction_color
                  FROM game_edge e
                  JOIN game_claimant c ON c.id = e.owner_claimant_id AND c.type = "group"
                  JOIN game_crew cr     ON cr.claimant_id = c.id
                  JOIN game_faction f   ON f.id = cr.faction_id';
        $params = [];
        if ($minLon !== null && $minLat !== null && $maxLon !== null && $maxLat !== null) {
            $sql .= ' WHERE e.max_lat >= ? AND e.min_lat <= ? AND e.max_lon >= ? AND e.min_lon <= ?';
            $params = [$minLat, $maxLat, $minLon, $maxLon];
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'length_m'   => (float)$r['length_m'],
                'lat'        => (float)$r['lat'],
                'lon'        => (float)$r['lon'],
                'key'        => (string)$r['faction_key'],
                'color'      => (string)$r['faction_color'],
                'faction_id' => (int)$r['faction_id'],
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed>|false $r
     * @return array{id:int,key:string,name:string,color:string}|null
     */
    private static function shape(array|false $r): ?array
    {
        if ($r === false) {
            return null;
        }
        return [
            'id'    => (int)$r['id'],
            'key'   => (string)$r['key_slug'],
            'name'  => (string)$r['name'],
            'color' => (string)$r['color_hex'],
        ];
    }
}
