<?php
declare(strict_types=1);

namespace App\Game\Challenges;

use App\Support\Clock;
use DateTimeImmutable;
use PDO;

/**
 * Aufgaben/Challenges (GAME_CHALLENGES_BACKEND.md, Phase C) — v1.
 *
 * Bewusst zustandslos: Der Fortschritt wird je Anfrage LIVE aus dem
 * Ereignis-Strom (game_event, Phase A) der laufenden ISO-Woche gezählt. Damit
 * ist AC2 (idempotent bei Re-Ingest) ohne eigene Zähl-Persistenz erfüllt — ein
 * erneuter Ingest legt dieselben game_event-Zeilen (UNIQUE-dedupliziert), die
 * Zählung ändert sich nicht.
 *
 * Katalog v1 (global, für alle gleich): zwei Wochen-Aufgaben, beide aus dem
 * Strom ableitbar. `points_total` = Summe der Belohnungen aktuell erledigter
 * Aufgaben (Live-Sicht). Persistente Punkte-Akkumulation, Abzeichen-Vergabe und
 * die challenge_done-Mitteilung (AC3 Persistenz) sind bewusst zurückgestellt.
 */
final class ChallengeService
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array{challenges:list<array<string,mixed>>,points_total:int}
     */
    public function forUser(int $userId, string $lang): array
    {
        $de = self::normalizeLang($lang) === 'de';

        $now    = Clock::nowUtc();
        $monday = self::mondayOf($now);
        $expiresAt = $monday->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
        $mondayDate = $monday->format('Y-m-d');

        $newEdges = $this->countEvents('edge_new', 'user_id', $userId, $mondayDate);
        $captures = $this->countEvents('edge_taken', 'actor_user_id', $userId, $mondayDate);

        $challenges = [
            $this->buildChallenge(
                id: 'weekly_new_edges',
                title: $de ? 'Erschließe 5 neue Kanten' : 'Discover 5 new edges',
                detail: $de ? 'Diese Woche' : 'This week',
                progress: $newEdges,
                target: 5,
                rewardPoints: 50,
                badge: $de ? 'Entdecker' : 'Explorer',
                icon: 'map',
                expiresAt: $expiresAt,
            ),
            $this->buildChallenge(
                id: 'weekly_capture',
                title: $de ? 'Übernimm 3 Kanten' : 'Capture 3 edges',
                detail: $de ? 'Diese Woche' : 'This week',
                progress: $captures,
                target: 3,
                rewardPoints: 30,
                badge: $de ? 'Eroberer' : 'Conqueror',
                icon: 'flag',
                expiresAt: $expiresAt,
            ),
        ];

        $pointsTotal = 0;
        foreach ($challenges as $c) {
            if ($c['progress'] >= $c['target']) {
                $pointsTotal += (int)$c['reward_points'];
            }
        }

        return ['challenges' => $challenges, 'points_total' => $pointsTotal];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildChallenge(
        string $id,
        string $title,
        string $detail,
        int $progress,
        int $target,
        int $rewardPoints,
        string $badge,
        string $icon,
        string $expiresAt,
    ): array {
        return [
            'id'            => $id,
            'title'         => $title,
            'detail'        => $detail,
            'progress'      => min($progress, $target), // Anzeige nie über dem Ziel
            'target'        => $target,
            'reward_points' => $rewardPoints,
            'badge'         => $badge,
            'icon'          => $icon,
            'expires_at'    => $expiresAt,
            'period'        => 'weekly',
        ];
    }

    /**
     * Zählt distinct Kanten eines Ereignistyps für den Nutzer ab dem Wochen-
     * Montag (ridden_on). $userColumn ist user_id (Betroffener) oder
     * actor_user_id (Auslöser), je nach Aufgabe.
     */
    private function countEvents(string $type, string $userColumn, int $userId, string $sinceDate): int
    {
        $col = $userColumn === 'actor_user_id' ? 'actor_user_id' : 'user_id';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT edge_id)
               FROM game_event
              WHERE type = ? AND {$col} = ? AND edge_id IS NOT NULL AND ridden_on >= ?"
        );
        $stmt->execute([$type, $userId, $sinceDate]);
        return (int)$stmt->fetchColumn();
    }

    /** Montag (00:00 UTC) der ISO-Woche von $dt. */
    private static function mondayOf(DateTimeImmutable $dt): DateTimeImmutable
    {
        $offset = (int)$dt->format('N') - 1;
        return $dt->setTime(0, 0, 0)->modify("-{$offset} days");
    }

    /** 'de' für deutschsprachige Accept-Language-Header, sonst 'en'. */
    private static function normalizeLang(string $acceptLanguage): string
    {
        return stripos(trim($acceptLanguage), 'de') === 0 ? 'de' : 'en';
    }
}
