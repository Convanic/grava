<?php
declare(strict_types=1);

namespace App\Game;

use App\Support\Clock;

/**
 * Zeitlicher Verlauf der eigenen Revier-Kennzahlen (GameHistory_Backend_Spec.md).
 *
 * Zwei-Wege-Strategie:
 *  - **Vorwärts (exakt):** ein Cron (`game:snapshot-daily`) schreibt je Tag einen
 *    Snapshot pro Claimant (`GameRepository::allClaimantHoldings` → upsert).
 *  - **Rückwärts (einmalig, Näherung):** beim ersten Lauf pro Claimant Backfill aus
 *    `game_edge.owner_since` / `discovered_at`. Pionier ist dabei exakt (Erstbefahrer
 *    bleibt es dauerhaft), „gehalten" ist die Wachstumskurve des HEUTE gehaltenen
 *    Reviers (seither verlorene Kanten fehlen — dokumentierte Näherung).
 *
 * Der Lese-Pfad (`history`) aggregiert nur die Tabelle, ohne Recompute.
 */
final class GameHistoryService
{
    public function __construct(private readonly GameRepository $repo) {}

    /**
     * Verlaufspunkte eines Claimants im Fenster der letzten `$days` Tage.
     * @return array{points:list<array{date:string,held_edges:int,pioneered_edges:int,held_length_m:float}>}
     */
    public function history(int $claimantId, int $days): array
    {
        $since = Clock::nowUtc()->modify("-{$days} days")->format('Y-m-d');
        $points = [];
        foreach ($this->repo->dailySnapshots($claimantId, $since) as $r) {
            $points[] = [
                'date'            => $r['snapshot_date'],
                'held_edges'      => $r['held_edges'],
                'pioneered_edges' => $r['pioneered_edges'],
                'held_length_m'   => $r['held_length_m'],
            ];
        }
        return ['points' => $points];
    }

    /**
     * Self-Heal auf dem Lese-Pfad: stellt sicher, dass der heutige Snapshot des
     * Claimants existiert — wichtig auf Hostern OHNE täglichen Cron (der Chart
     * wächst dann allein durchs App-Öffnen). Backfillt beim allerersten Mal die
     * Vergangenheit. Idempotent + billig (ein meStats + Upsert).
     */
    public function ensureTodaySnapshot(int $claimantId): void
    {
        if (!$this->repo->hasDailySnapshots($claimantId)) {
            $this->backfill($claimantId);
        }
        $s = $this->repo->meStats($claimantId);
        $this->repo->upsertDailySnapshot(
            $claimantId, Clock::nowUtc()->format('Y-m-d'),
            $s['held'], $s['pioneered'], $s['held_length_m']
        );
    }

    /**
     * Tages-Snapshot über alle aktiven Claimants (Cron). Backfillt Claimants ohne
     * Historie einmalig. `$today` überschreibbar für Tests; sonst UTC-heute.
     * @return array{claimants:int,backfilled:int,date:string}
     */
    public function snapshotAll(?string $today = null): array
    {
        $date = $today ?? Clock::nowUtc()->format('Y-m-d');
        $holdings = $this->repo->allClaimantHoldings();
        $backfilled = 0;
        foreach ($holdings as $claimantId => $h) {
            if (!$this->repo->hasDailySnapshots($claimantId)) {
                $this->backfill($claimantId);
                $backfilled++;
            }
            $this->repo->upsertDailySnapshot(
                $claimantId, $date, $h['held'], $h['pioneered'], $h['held_length_m']
            );
        }
        return ['claimants' => count($holdings), 'backfilled' => $backfilled, 'date' => $date];
    }

    /**
     * Einmaliges Backfill: rekonstruiert Step-Punkte aus den Erwerbs-/Erschließungs-
     * Daten der aktuell gehaltenen/erschlossenen Kanten. Ein Punkt je Kalendertag mit
     * Änderung (der Client interpoliert linear dazwischen). Idempotenz stellt der
     * Aufrufer über `hasDailySnapshots` sicher.
     * @return int Anzahl eingefügter Punkte
     */
    public function backfill(int $claimantId): int
    {
        $data = $this->repo->edgeAcquisitionDates($claimantId);

        // Deltas je Tag zusammenführen (gehaltene Kanten + Länge, Pionierkanten).
        $heldDelta = [];   // date => count
        $lenDelta  = [];   // date => meters
        $pioDelta  = [];   // date => count
        foreach ($data['held'] as $row) {
            $heldDelta[$row['d']] = ($heldDelta[$row['d']] ?? 0) + 1;
            $lenDelta[$row['d']]  = ($lenDelta[$row['d']] ?? 0.0) + $row['len'];
        }
        foreach ($data['pioneered'] as $d) {
            $pioDelta[$d] = ($pioDelta[$d] ?? 0) + 1;
        }

        $dates = array_keys($heldDelta + $pioDelta);
        sort($dates);

        $held = 0; $pio = 0; $len = 0.0; $inserted = 0;
        foreach ($dates as $d) {
            $held += $heldDelta[$d] ?? 0;
            $len  += $lenDelta[$d] ?? 0.0;
            $pio  += $pioDelta[$d] ?? 0;
            $this->repo->upsertDailySnapshot($claimantId, $d, $held, $pio, $len);
            $inserted++;
        }
        return $inserted;
    }
}
