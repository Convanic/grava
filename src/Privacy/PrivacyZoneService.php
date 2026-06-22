<?php
declare(strict_types=1);

namespace App\Privacy;

use App\Game\EdgeRecalculator;
use App\Game\GameRepository;
use App\Support\Clock;
use DateTimeImmutable;
use PDO;

/**
 * Orchestriert die Privatzonen (PRIVACY_ZONE_BACKEND.md):
 * Lesen/Setzen/Löschen plus die rückwirkende Bereinigung beim Setzen
 * (Revier-Pässe in der Zone invalidieren + betroffene Kanten neu berechnen).
 *
 * Die Game-Abhängigkeiten sind optional (nullable): ist das Spiel
 * deaktiviert, werden Zonen weiter verwaltet, nur ohne Revier-Bereinigung.
 */
final class PrivacyZoneService
{
    public function __construct(
        private readonly PrivacyZoneRepository $zones,
        private readonly ?GameRepository $game = null,
        private readonly ?EdgeRecalculator $recalc = null,
        private readonly ?PDO $pdo = null,
    ) {}

    /**
     * Eigene Zone (inkl. enabled-Flag) oder null, wenn nicht gesetzt.
     *
     * @return array{lat:float,lon:float,radius_m:int,enabled:bool}|null
     */
    public function get(int $userId): ?array
    {
        return $this->zones->find($userId);
    }

    /**
     * Setzt/aktualisiert die Zone. radius_m wird auf 200…2000 geklemmt.
     * Bei aktiver Zone wird rückwirkend bereinigt (siehe cleanup()).
     *
     * @return array{lat:float,lon:float,radius_m:int,enabled:bool}
     */
    public function put(int $userId, float $lat, float $lon, int $radiusM, bool $enabled, ?DateTimeImmutable $now = null): array
    {
        $radiusM = PrivacyZone::clampRadius($radiusM);
        $saved = $this->zones->upsert($userId, $lat, $lon, $radiusM, $enabled);

        if ($enabled) {
            // Rückwirkende Bereinigung. Spec erlaubt asynchron; wir machen es
            // synchron, aber strikt auf die betroffenen Kanten begrenzt (klein).
            $this->cleanup($userId, new PrivacyZone($lat, $lon, $radiusM), $now);
        }

        return $saved;
    }

    /**
     * Entfernt die Zone (Schutz für die Zukunft aufgehoben). Bereits
     * invalidierte Pässe bleiben invalidiert — KEIN Auto-Restore (bewusst,
     * dokumentiert in PRIVACY_ZONE_BACKEND.md §7).
     */
    public function delete(int $userId): void
    {
        $this->zones->delete($userId);
    }

    /**
     * Invalidiert die Pässe des Nutzers auf Kanten innerhalb der Zone und
     * berechnet diese Kanten neu. Liefert die Anzahl betroffener Kanten.
     */
    public function cleanup(int $userId, PrivacyZone $zone, ?DateTimeImmutable $now = null): int
    {
        if ($this->game === null || $this->recalc === null || $this->pdo === null) {
            return 0;
        }
        $now ??= Clock::nowUtc();

        // Kandidaten über das BBox-Fenster der Zone, dann exakt geometrisch prüfen.
        [$minLat, $minLon, $maxLat, $maxLon] = $this->bbox($zone);
        $candidates = $this->game->edgeIdsInBbox($minLon, $minLat, $maxLon, $maxLat);

        $inZone = [];
        foreach ($candidates as $edgeId) {
            $edge = $this->game->edgeById($edgeId);
            if ($edge === null) {
                continue;
            }
            $geom = json_decode((string)($edge['geom_geojson'] ?? ''), true);
            $coords = is_array($geom) ? ($geom['coordinates'] ?? null) : null;
            if (is_array($coords) && $zone->intersectsPolyline($coords)) {
                $inZone[] = $edgeId;
            }
        }
        if ($inZone === []) {
            return 0;
        }

        // Pässe des Nutzers auf diesen Kanten soft-invalidieren.
        $ph = implode(',', array_fill(0, count($inZone), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE game_edge_pass
                SET invalidated_at = ?, invalidated_by = ?, invalid_reason = 'privacy_zone'
              WHERE user_id = ? AND invalidated_at IS NULL AND edge_id IN ($ph)"
        );
        $stmt->execute([$now->format('Y-m-d H:i:s.v'), $userId, $userId, ...$inZone]);

        // Betroffene Kanten neu berechnen (event-sourced aus den verbleibenden Pässen).
        $this->game->resetEdgeCaches($inZone);
        foreach ($inZone as $edgeId) {
            $this->game->refreshEdgeDiscovery($edgeId);
            $this->recalc->recalculate($edgeId, $now);
        }
        return count($inZone);
    }

    /** @return array{0:float,1:float,2:float,3:float} [minLat,minLon,maxLat,maxLon] */
    private function bbox(PrivacyZone $zone): array
    {
        $dLat = $zone->radiusM / 111320.0;
        $cos = cos(deg2rad($zone->lat));
        $dLon = $zone->radiusM / (111320.0 * (abs($cos) < 1e-6 ? 1e-6 : $cos));
        return [
            $zone->lat - $dLat,
            $zone->lon - abs($dLon),
            $zone->lat + $dLat,
            $zone->lon + abs($dLon),
        ];
    }
}
