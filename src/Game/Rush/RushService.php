<?php
declare(strict_types=1);

namespace App\Game\Rush;

use App\Engagement\NotificationService;
use App\Game\Admin\GameAuditService;
use App\Game\Crew\CrewRepository;
use App\Game\EdgeRecalculator;
use App\Game\GameConfig;
use App\Game\GameRepository;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Rush-Lebenszyklus + Koordination (GAME_RUSH_BACKEND.md §4–§6).
 *
 * Der Rush-KERN (Pass-Tagging, Multiplikator, Übernahme) ist server-kanonisch
 * und liegt in {@see \App\Game\GameIngestionService} (Auto-Tag) und
 * {@see EdgeRecalculator} (Gate + Multiplikator). Dieser Service kümmert sich um
 * Anlegen/Abbrechen/Lesen/RSVP, die zeitgesteuerten Statusübergänge (lazy +
 * Cron-Tick) und die Push-Hooks.
 */
final class RushService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly RushRepository $rushes,
        private readonly CrewRepository $crews,
        private readonly GameRepository $game,
        private readonly EdgeRecalculator $recalc,
        private readonly GameConfig $config,
        private readonly ?NotificationService $notifications = null,
        private readonly ?GameAuditService $audit = null,
    ) {}

    // ----------------------------------------------------------------
    // Endpunkte (§5)
    // ----------------------------------------------------------------

    /**
     * §5.1 — Rush anlegen (nur Captain). Captain-/Cooldown-/Überlappungs-Guards.
     *
     * @return array<string,mixed> DTO des angelegten Rushes (§5.5)
     */
    public function create(
        int $userId,
        string $startAtIso,
        ?int $windowHours,
        ?float $meetupLat,
        ?float $meetupLon,
        ?DateTimeImmutable $now = null,
        ?string $note = null,
    ): array {
        if (!$this->config->bool('rush_enabled')) {
            throw RushException::conflict('Rush ist derzeit deaktiviert.');
        }
        $now ??= Clock::nowUtc();

        $membership = $this->crews->membershipOf($userId);
        if ($membership === null) {
            throw RushException::forbidden('Nur der Captain einer Crew kann einen Rush ansetzen.');
        }
        if ($membership['role'] !== 'captain') {
            throw RushException::forbidden('Nur der Captain kann einen Rush ansetzen.');
        }
        $crewId = $membership['crew_id'];

        $start = $this->parseIso($startAtIso);
        if ($start === null) {
            throw RushException::validation('start_at ist kein gültiger ISO-8601-Zeitpunkt.');
        }

        // Fensterlänge: Default aus Config, Client darf überschreiben, server-gedeckelt.
        $defaultHours = max(1, $this->config->int('rush_window_hours'));
        $maxHours     = max(1, $this->config->int('rush_window_hours_max'));
        $hours = ($windowHours === null || $windowHours <= 0) ? $defaultHours : $windowHours;
        $hours = min($hours, $maxHours);
        $end   = $start->modify("+{$hours} hours");

        // start_at muss in der Zukunft liegen (rush_requires_announcement).
        if ($this->config->bool('rush_requires_announcement') && $start <= $now) {
            throw RushException::validation('start_at muss in der Zukunft liegen.');
        }

        $startStr = $this->toMysql($start);
        $endStr   = $this->toMysql($end);

        if ($this->rushes->overlapExists($crewId, $startStr, $endStr)) {
            throw RushException::conflict('Es existiert bereits ein offener Rush in diesem Zeitfenster.');
        }
        $cooldownDays = $this->config->int('rush_cooldown_days');
        if ($cooldownDays > 0) {
            $last = $this->rushes->lastRushStart($crewId);
            if ($last !== null) {
                $allowedFrom = (new DateTimeImmutable($last, new DateTimeZone('UTC')))
                    ->modify("+{$cooldownDays} days");
                if ($start < $allowedFrom) {
                    throw RushException::conflict(
                        'Cooldown aktiv: zwischen zwei Rushes derselben Crew müssen '
                        . $cooldownDays . ' Tage liegen.'
                    );
                }
            }
        }

        $multiplier = $this->config->float('rush_multiplier'); // Snapshot
        $rushId = $this->rushes->create(
            $crewId, $userId, $startStr, $endStr, $multiplier, $meetupLat, $meetupLon,
            $this->sanitizeNote($note),
        );

        $this->audit?->record($userId, 'rush_create', 'crew:' . $crewId, [
            'rush_id' => $rushId, 'start_at' => $startStr, 'end_at' => $endStr,
        ]);

        // Push rush_invite an alle Crew-Mitglieder (Self wird von notify() gefiltert).
        $this->pushToMembers($crewId, $userId, 'rush_invite', $rushId);

        return $this->dtoById($rushId, $now);
    }

    /** §5.2 — aktiver/nächster Rush der eigenen Crew (+ RSVPs) oder null. */
    public function myRush(int $userId, ?DateTimeImmutable $now = null): ?array
    {
        $now ??= Clock::nowUtc();
        $membership = $this->crews->membershipOf($userId);
        if ($membership === null) {
            return null;
        }
        $crewId = $membership['crew_id'];
        $this->tickCrew($crewId, $now); // lazy: Status folgt der Zeit (§4)

        $row = $this->rushes->activeOrNextForCrew($crewId);
        if ($row === null) {
            return null;
        }
        return [
            'rush'  => $this->dtoFromRow($row, $now),
            'rsvps' => $this->rushes->rsvps((int)$row['id']),
        ];
    }

    /** §5.3 — Zu-/Absage (nur Crew-Mitglieder, KEIN Scoring-Effekt). */
    public function rsvp(int $userId, int $rushId, string $state, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::nowUtc();
        if (!in_array($state, ['yes', 'no', 'maybe'], true)) {
            throw RushException::validation('state muss yes|no|maybe sein.');
        }
        $rush = $this->rushes->byId($rushId);
        if ($rush === null) {
            throw RushException::notFound();
        }
        $membership = $this->crews->membershipOf($userId);
        if ($membership === null || $membership['crew_id'] !== (int)$rush['crew_id']) {
            throw RushException::forbidden('Nur Mitglieder der Crew dürfen zu-/absagen.');
        }
        $this->rushes->rsvpUpsert($rushId, $userId, $state);

        $fresh = $this->rushes->byId($rushId);
        return [
            'rush'  => $this->dtoFromRow($fresh, $now),
            'rsvps' => $this->rushes->rsvps($rushId),
        ];
    }

    /** §5.4 — abbrechen (nur Captain, nur 'planned'). */
    public function cancel(int $userId, int $rushId): void
    {
        $rush = $this->rushes->byId($rushId);
        if ($rush === null) {
            throw RushException::notFound();
        }
        $membership = $this->crews->membershipOf($userId);
        if ($membership === null
            || $membership['crew_id'] !== (int)$rush['crew_id']
            || $membership['role'] !== 'captain'
        ) {
            throw RushException::forbidden('Nur der Captain kann den Rush abbrechen.');
        }
        if ((string)$rush['status'] !== 'planned') {
            throw RushException::conflict('Nur ein geplanter Rush kann abgebrochen werden.');
        }
        $this->rushes->setStatus($rushId, 'cancelled');
        $this->audit?->record($userId, 'rush_cancel', 'rush:' . $rushId);
    }

    // ----------------------------------------------------------------
    // Lebenszyklus / Statusübergänge (§4)
    // ----------------------------------------------------------------

    /**
     * Cron-Tick (game:rush-tick): überführt alle fälligen Rushes in ihren
     * Zielstatus, rechnet betroffene Kanten neu und stößt rush_result-Push an.
     *
     * @return array{activated:int,completed:int,expired:int}
     */
    public function tick(?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::nowUtc();
        $stats = ['activated' => 0, 'completed' => 0, 'expired' => 0];
        foreach ($this->rushes->ticklableRushes($this->toMysql($now)) as $r) {
            $stats = $this->transition($r, $now, $stats);
        }
        return $stats;
    }

    /** Lazy-Tick auf die offenen Rushes EINER Crew (Lese-/Ingest-Pfad). */
    private function tickCrew(int $crewId, DateTimeImmutable $now): void
    {
        $nowStr = $this->toMysql($now);
        $stats  = ['activated' => 0, 'completed' => 0, 'expired' => 0];
        foreach ($this->rushes->openForCrew($crewId) as $r) {
            $row = [
                'id'       => (int)$r['id'],
                'crew_id'  => $crewId,
                'status'   => (string)$r['status'],
                'start_at' => (string)$r['start_at'],
                'end_at'   => (string)$r['end_at'],
            ];
            if ($row['start_at'] <= $nowStr || $row['end_at'] <= $nowStr) {
                $this->transition($row, $now, $stats);
            }
        }
    }

    /**
     * @param array{id:int,crew_id:int,status:string,start_at:string,end_at:string} $r
     * @param array{activated:int,completed:int,expired:int} $stats
     * @return array{activated:int,completed:int,expired:int}
     */
    private function transition(array $r, DateTimeImmutable $now, array $stats): array
    {
        $nowStr = $this->toMysql($now);
        $rushId = $r['id'];

        if ($nowStr > $r['end_at']) {
            $qualified = $this->rushes->distinctRidden($rushId) >= $this->config->int('rush_min_crew_size');
            $newStatus = $qualified ? 'completed' : 'expired';
            $this->rushes->setStatus($rushId, $newStatus);
            // Gezielter Recompute: bei 'expired' fällt der Multiplikator weg.
            $this->recomputeRushEdges($rushId, $now);
            // Actor = Rush-Ersteller (FK notifications.actor_id → users.id; ein
            // System-Actor 0 würde die FK verletzen). Der Captain ist damit
            // self-gefiltert; er sieht das Ergebnis über GET …/rush.
            $createdBy = (int)($this->rushes->byId($rushId)['created_by'] ?? 0);
            $this->pushToMembers($r['crew_id'], $createdBy, 'rush_result', $rushId);
            $stats[$newStatus === 'completed' ? 'completed' : 'expired']++;
            return $stats;
        }

        if ($r['status'] === 'planned' && $nowStr >= $r['start_at']) {
            $this->rushes->setStatus($rushId, 'active');
            $stats['activated']++;
        }
        return $stats;
    }

    /** Rechnet die vom Rush getaggten Kanten neu (gezielt, event-sourced §7). */
    private function recomputeRushEdges(int $rushId, DateTimeImmutable $now): void
    {
        foreach ($this->game->rushTaggedEdgeIds($rushId) as $edgeId) {
            $this->recalc->recalculate($edgeId, $now);
        }
    }

    // ----------------------------------------------------------------
    // DTO + Push-Helfer
    // ----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function dtoById(int $rushId, DateTimeImmutable $now): array
    {
        $row = $this->rushes->byId($rushId);
        if ($row === null) {
            throw RushException::notFound();
        }
        return $this->dtoFromRow($row, $now);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed> DTO §5.5 (snake_case)
     */
    private function dtoFromRow(array $row, DateTimeImmutable $now): array
    {
        $rushId  = (int)$row['id'];
        $ridden  = $this->rushes->distinctRidden($rushId);
        $minCrew = $this->config->int('rush_min_crew_size');
        return [
            'id'                     => $rushId,
            'crew_id'                => (int)$row['crew_id'],
            'status'                 => (string)$row['status'],
            'start_at'               => $this->iso($row['start_at']),
            'end_at'                 => $this->iso($row['end_at']),
            'multiplier'             => (float)$row['multiplier'],
            'meetup_lat'             => $row['meetup_lat'] !== null ? (float)$row['meetup_lat'] : null,
            'meetup_lon'             => $row['meetup_lon'] !== null ? (float)$row['meetup_lon'] : null,
            'note'                   => isset($row['note']) && $row['note'] !== null ? (string)$row['note'] : null,
            'created_by_handle'      => $row['created_by_handle'] !== null ? (string)$row['created_by_handle'] : null,
            'participants_confirmed' => $this->rushes->confirmedCount($rushId),
            'participants_ridden'    => $ridden,
            'qualified'              => $ridden >= $minCrew,
            'edges_captured'         => $this->rushes->edgesCaptured($rushId, (int)$row['crew_claimant_id']),
        ];
    }

    /**
     * Push an alle Crew-Mitglieder. $actorUserId=0 (System) bei rush_result —
     * dann sendet notify() an alle (keine Self-Filterung greift). Deep-Link
     * über subject_type='rush'. Best effort (Fehler dürfen nichts abbrechen).
     */
    private function pushToMembers(int $crewId, int $actorUserId, string $type, int $rushId): void
    {
        if ($this->notifications === null) {
            return;
        }
        try {
            foreach ($this->crews->members($crewId) as $m) {
                $this->notifications->notify((int)$m['user_id'], $actorUserId, $type, 'rush', $rushId);
            }
        } catch (Throwable $e) {
            error_log('rush push (' . $type . '): ' . $e->getMessage());
        }
    }

    /**
     * Rush-Hinweis normalisieren (§13.2): trim, Steuerzeichen raus, auf 280
     * Zeichen kappen (NICHT ablehnen), leer → NULL. Plaintext — kein HTML/
     * Markdown; XSS-Sicherheit entsteht durch JSON-Auslieferung + Client-seitige
     * Plaintext-Anzeige. Gleiche Politik wie andere User-Freitexte (Crew-Name).
     */
    private function sanitizeNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }
        // C0-Steuerzeichen entfernen, Tab/Zeilenumbruch aber erhalten.
        $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note) ?? '';
        $note = trim($note);
        if ($note === '') {
            return null;
        }
        return mb_substr($note, 0, 280);
    }

    private function parseIso(string $iso): ?DateTimeImmutable
    {
        $iso = trim($iso);
        if ($iso === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function toMysql(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    /** DB-DATETIME(3) ('Y-m-d H:i:s.v') → ISO-8601 mit Z (iOS-Decoder, §11). */
    private function iso(string $mysqlDatetime): string
    {
        return str_replace(' ', 'T', $mysqlDatetime) . 'Z';
    }
}
