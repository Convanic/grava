<?php
declare(strict_types=1);

namespace App\Game\Crew;

use App\Game\Admin\GameAuditService;
use App\Game\EdgeRecalculator;
use App\Game\Faction\FactionRepository;
use App\Game\Faction\FactionService;
use App\Game\GameConfig;
use App\Game\GameMath;
use App\Game\GameRepository;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Crew-Geschäftslogik (Stufe 2): create/join/leave/transfer + Profil/me.
 *
 * Mitgliedschaftsänderungen lösen einen synchronen Teil-Recompute der
 * Fenster-Kanten des Users aus (Spec §4.4) — die Präsenz wandert über den
 * effektiven Claimant auf die Crew bzw. zurück auf den Rider. Captain-Regel
 * und FK-sichere Auflösung gemäß Spec §5.1.
 */
final class CrewService
{
    private const JOIN_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // ohne 0/O/1/I/L

    public function __construct(
        private readonly PDO $pdo,
        private readonly CrewRepository $crews,
        private readonly GameRepository $game,
        private readonly EdgeRecalculator $recalc,
        private readonly GameConfig $config,
        private readonly GameAuditService $audit,
        // Stufe 3: optional — reichert das Crew-Payload um die Fraktion an.
        private readonly ?FactionRepository $factions = null,
    ) {}

    /** @return array<string,mixed> */
    public function create(int $userId, string $name, ?DateTimeImmutable $now = null): array
    {
        $name = $this->validateName($name);
        $now ??= Clock::nowUtc();

        return $this->transactional(function () use ($userId, $name, $now): array {
            if ($this->crews->membershipOf($userId) !== null) {
                $this->leaveInternal($userId, $now); // Captain-Regel greift hier
            }
            $slug     = $this->uniqueSlug($name);
            $joinCode = $this->uniqueJoinCode();
            $claimant = $this->crews->createGroupClaimant();
            $crewId   = $this->crews->createCrew($claimant, $name, $slug, $userId, $joinCode);
            $this->crews->addMember($userId, $crewId, 'captain');

            $this->recomputeUserEdges($userId, $now);
            $this->audit->record($userId, 'crew_create', $slug);
            return $this->crewPayload($crewId, true);
        });
    }

    /** @return array<string,mixed> */
    public function join(int $userId, string $joinCode, ?DateTimeImmutable $now = null): array
    {
        $joinCode = strtoupper(trim($joinCode));
        if ($joinCode === '') {
            throw CrewException::validation('join_code erforderlich.');
        }
        $now ??= Clock::nowUtc();

        return $this->transactional(function () use ($userId, $joinCode, $now): array {
            $crew = $this->crews->crewByJoinCode($joinCode);
            if ($crew === null) {
                throw CrewException::notFound('Ungültiger Einladungscode.');
            }
            $crewId  = (int)$crew['id'];
            $current = $this->crews->membershipOf($userId);
            if ($current !== null && $current['crew_id'] === $crewId) {
                return $this->crewPayload($crewId, $current['role'] === 'captain');
            }
            if ($current !== null) {
                $this->leaveInternal($userId, $now); // Captain-Regel greift hier
            }
            $max = $this->config->int('crew_max_members');
            if ($max > 0 && $this->crews->memberCount($crewId) >= $max) {
                throw CrewException::full();
            }
            $this->crews->addMember($userId, $crewId, 'member');

            $this->recomputeUserEdges($userId, $now);
            $this->audit->record($userId, 'crew_join', (string)$crew['slug']);
            return $this->crewPayload($crewId, false);
        });
    }

    /** @return array{left:bool,dissolved:bool} */
    public function leave(int $userId, ?DateTimeImmutable $now = null): array
    {
        $now ??= Clock::nowUtc();
        return $this->transactional(fn (): array => $this->leaveInternal($userId, $now));
    }

    /** @return array<string,mixed> Crew-Payload nach der Übertragung (Aufrufer ist nun Member). */
    public function transfer(int $captainUserId, int $targetUserId): array
    {
        return $this->transactional(function () use ($captainUserId, $targetUserId): array {
            $membership = $this->crews->membershipOf($captainUserId);
            if ($membership === null) {
                throw CrewException::notMember();
            }
            if ($membership['role'] !== 'captain') {
                throw CrewException::forbidden('Nur der Captain kann die Rolle übertragen.');
            }
            if ($targetUserId === $captainUserId) {
                throw CrewException::validation('Captain-Rolle kann nicht an sich selbst übertragen werden.');
            }
            $target = $this->crews->membershipOf($targetUserId);
            if ($target === null || $target['crew_id'] !== $membership['crew_id']) {
                throw CrewException::validation('Ziel muss Mitglied derselben Crew sein.');
            }
            $crewId = $membership['crew_id'];
            $this->crews->setRole($captainUserId, 'member');
            $this->crews->setRole($targetUserId, 'captain');
            $this->crews->setOwnerUser($crewId, $targetUserId);

            $crew = $this->crews->crewById($crewId);
            $this->audit->record($captainUserId, 'crew_transfer', (string)($crew['slug'] ?? ''), ['to' => $targetUserId]);
            return $this->crewPayload($crewId, false);
        });
    }

    /**
     * §12.3 — Notbesetzung des Captains. Jedes Mitglied darf ein Mitglied zum
     * Captain machen, ABER nur solange die Crew keinen (aktiven) Captain hat
     * (Anti-Hijacking). Sonst gilt der reguläre transfer-Weg → 409.
     *
     * @return array<string,mixed> Aktualisiertes Crew-Profil (Form wie GET /crews/{slug}).
     */
    public function claimCaptain(int $userId, string $slug, string $handle): array
    {
        $handle = ltrim(trim($handle), '@');
        if ($handle === '') {
            throw CrewException::validation('user_handle erforderlich.');
        }
        return $this->transactional(function () use ($userId, $slug, $handle): array {
            $crew = $this->crews->crewBySlug(trim($slug));
            if ($crew === null) {
                throw CrewException::notFound('Crew nicht gefunden.');
            }
            $crewId = (int)$crew['id'];

            // Nur Mitglieder der Crew dürfen die Notbesetzung auslösen (§12.d → 403).
            $requester = $this->crews->membershipOf($userId);
            if ($requester === null || $requester['crew_id'] !== $crewId) {
                throw CrewException::forbidden('Nur Mitglieder der Crew dürfen einen Captain bestimmen.');
            }
            // Anti-Hijacking-Guard (§12.b → 409).
            if ($this->crews->hasActiveCaptain($crewId)) {
                throw CrewException::conflict('Crew hat bereits einen Captain — nutze die reguläre Übergabe.');
            }
            // Ziel muss aktives Mitglied der Crew sein (§12.c → 404).
            $target = $this->crews->memberByHandle($crewId, $handle);
            if ($target === null) {
                throw CrewException::notFound('Mitglied nicht gefunden.');
            }

            // Eindeutige Captain-Zeile herstellen (etwaige tote Captain-Zeile weg).
            $this->crews->clearCaptains($crewId);
            $this->crews->setRole($target['user_id'], 'captain');
            $this->crews->setOwnerUser($crewId, $target['user_id']);

            $this->audit->record($userId, 'crew_captain_claim', (string)$crew['slug'], [
                'to_user_id' => $target['user_id'], 'to_handle' => $handle,
            ]);
            return $this->crewPayload($crewId, false);
        });
    }

    /**
     * §12.1 — Heilt captain-lose, nicht-leere Crews (Altbestand), indem das
     * älteste aktive Mitglied zum Captain promotet wird. Reiner Rollen-/Owner-
     * Wechsel (Group-Claimant unverändert) → kein Edge-Recompute nötig.
     *
     * @return list<array{slug:string,promoted_user_id:int}>
     */
    public function healCaptainlessCrews(): array
    {
        return $this->transactional(function (): array {
            $healed = [];
            foreach ($this->crews->captainlessCrews() as $crew) {
                $crewId = $crew['crew_id'];
                $newCap = $this->crews->oldestMember($crewId, null, true)
                    ?? $this->crews->oldestMember($crewId);
                if ($newCap === null) {
                    continue; // keine (aktiven) Mitglieder → nicht heilbar
                }
                $this->crews->clearCaptains($crewId);
                $this->crews->setRole($newCap, 'captain');
                $this->crews->setOwnerUser($crewId, $newCap);
                $this->audit->record($newCap, 'crew_captain_heal', $crew['slug'], ['crew_id' => $crewId]);
                $healed[] = ['slug' => $crew['slug'], 'promoted_user_id' => $newCap];
            }
            return $healed;
        });
    }

    /**
     * §12.1 — Invariante bei Account-Löschung: ein Captain darf NIE eine
     * nicht-leere Crew ohne Captain hinterlassen. Vor dem Entfernen wird das
     * älteste verbleibende Mitglied promotet; ist der User das letzte Mitglied,
     * wird die Crew regulär (mit Recompute) aufgelöst.
     */
    public function handleAccountDeletion(int $userId, ?DateTimeImmutable $now = null): void
    {
        $now ??= Clock::nowUtc();
        $this->transactional(function () use ($userId, $now): void {
            $m = $this->crews->membershipOf($userId);
            if ($m === null) {
                return;
            }
            $crewId = $m['crew_id'];
            if ($m['role'] === 'captain' && $this->crews->memberCount($crewId) > 1) {
                $newCap = $this->crews->oldestMember($crewId, $userId, true)
                    ?? $this->crews->oldestMember($crewId, $userId);
                if ($newCap !== null) {
                    $this->crews->setRole($newCap, 'captain');
                    $this->crews->setOwnerUser($crewId, $newCap);
                    $this->crews->setRole($userId, 'member'); // self degradieren, damit leaveInternal nicht blockt
                }
            }
            $this->leaveInternal($userId, $now);
        });
    }

    /** @return array<string,mixed>|null Eigene Crew (mit join_code, wenn Captain) oder null. */
    public function me(int $userId): ?array
    {
        $m = $this->crews->membershipOf($userId);
        if ($m === null) {
            return null;
        }
        return $this->crewPayload($m['crew_id'], $m['role'] === 'captain');
    }

    /** @return array<string,mixed>|null Öffentliches Crew-Profil (ohne join_code). */
    public function profile(string $slug): ?array
    {
        $crew = $this->crews->crewBySlug(trim($slug));
        if ($crew === null) {
            return null;
        }
        return $this->crewPayload((int)$crew['id'], false);
    }

    /**
     * Crew-Rangliste (nur Mitglieder, siehe backend/CREW_LEADERBOARD_BACKEND.md):
     * pro Mitglied Präsenz-Beitrag auf crew-eigenen Kanten, getragenes Gebiet
     * (Kanten, wo es Top-Beitragender ist) und 90-Tage-Aktivität. Reine
     * Lese-Aggregation; invalidierte Pässe ausgeschlossen.
     *
     * @return array{members:list<array<string,mixed>>}
     */
    public function leaderboard(string $slug, int $requesterUserId, ?DateTimeImmutable $now = null): array
    {
        $crew = $this->crews->crewBySlug(trim($slug));
        if ($crew === null) {
            throw CrewException::notFound();
        }
        $crewId = (int)$crew['id'];
        $req = $this->crews->membershipOf($requesterUserId);
        if ($req === null || $req['crew_id'] !== $crewId) {
            throw CrewException::forbidden('Nur Crew-Mitglieder dürfen die Rangliste sehen.');
        }

        $now ??= Clock::nowUtc();
        $window = $this->config->int('presence_window_days');
        $since  = $now->modify("-{$window} days")->format('Y-m-d');
        $claimantId = (int)$crew['claimant_id'];

        $members = $this->crews->members($crewId);
        $userIds = array_map(static fn (array $m): int => $m['user_id'], $members);

        $contribution = array_fill_keys($userIds, 0.0);
        $heldEdges    = array_fill_keys($userIds, 0);
        $heldLength   = array_fill_keys($userIds, 0.0);

        $ownedEdges = $this->crews->crewOwnedEdges($claimantId); // edge_id => length_m
        if ($ownedEdges !== [] && $userIds !== []) {
            $passes = $this->crews->passesOnEdgesForUsers(array_keys($ownedEdges), $userIds, $since);
            $perEdgePerUser = []; // [edge_id][user_id] => gewichtete Präsenz
            foreach ($passes as $p) {
                $w = GameMath::presenceWeight($this->ageDays($p['ridden_at'], $now), $window);
                $uid = $p['user_id'];
                $contribution[$uid] = ($contribution[$uid] ?? 0.0) + $w;
                $eid = $p['edge_id'];
                $perEdgePerUser[$eid][$uid] = ($perEdgePerUser[$eid][$uid] ?? 0.0) + $w;
            }
            foreach ($perEdgePerUser as $eid => $byUser) {
                ksort($byUser); // niedrigste user_id zuerst => deterministischer Tie-Break
                $topUser = null;
                $topW = -1.0;
                foreach ($byUser as $uid => $w) {
                    if ($w > $topW) {
                        $topW = $w;
                        $topUser = (int)$uid;
                    }
                }
                if ($topUser !== null) {
                    $heldEdges[$topUser] = ($heldEdges[$topUser] ?? 0) + 1;
                    $heldLength[$topUser] = ($heldLength[$topUser] ?? 0.0) + $ownedEdges[$eid];
                }
            }
        }

        $activity = $this->crews->activityForUsers($userIds, $since);

        $out = [];
        foreach ($members as $m) {
            $uid = $m['user_id'];
            $out[] = [
                'handle'                => $m['handle'],
                'role'                  => $m['role'],
                'presence_contribution' => round($contribution[$uid] ?? 0.0, 4),
                'held_edges'            => $heldEdges[$uid] ?? 0,
                'held_length_m'         => round($heldLength[$uid] ?? 0.0, 2),
                'activity_distance_m'   => round($activity[$uid]['distance'] ?? 0.0, 2),
                'activity_rides'        => $activity[$uid]['rides'] ?? 0,
            ];
        }
        return ['members' => $out];
    }

    private function ageDays(string $mysqlDatetime, DateTimeImmutable $now): float
    {
        $dt = new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));
        return max(0.0, ($now->getTimestamp() - $dt->getTimestamp()) / 86400.0);
    }

    // ----------------------------------------------------------------
    // intern
    // ----------------------------------------------------------------

    /** @return array{left:bool,dissolved:bool} */
    private function leaveInternal(int $userId, DateTimeImmutable $now): array
    {
        $membership = $this->crews->membershipOf($userId);
        if ($membership === null) {
            throw CrewException::notMember();
        }
        $crewId = $membership['crew_id'];
        $count  = $this->crews->memberCount($crewId);
        if ($membership['role'] === 'captain' && $count > 1) {
            throw CrewException::captainMustTransfer();
        }
        $dissolving = $count === 1;
        $crew       = $this->crews->crewById($crewId);
        $groupClaimant = (int)($crew['claimant_id'] ?? 0);

        // Kanten, die neu zu rechnen sind: die Fenster-Kanten des Users plus —
        // bei Auflösung — alle vom Group-Claimant gehaltenen Kanten (Safety-Net,
        // damit der Owner garantiert weg ist, bevor wir den Claimant löschen).
        $extra = $dissolving ? $this->game->edgeIdsOwnedByClaimant($groupClaimant) : [];

        $this->crews->removeMember($userId);
        $this->recomputeUserEdges($userId, $now, $extra);

        if ($dissolving) {
            $this->crews->deleteCrew($crewId);          // CASCADE räumt Member
            $this->crews->deleteClaimant($groupClaimant);
        }
        $this->audit->record($userId, 'crew_leave', (string)($crew['slug'] ?? ''), ['dissolved' => $dissolving]);
        return ['left' => true, 'dissolved' => $dissolving];
    }

    /**
     * Rechnet die im Fenster betroffenen Kanten des Users (+ optional extra)
     * synchron neu. Kein Cache-Reset → Hysterese bleibt korrekt.
     *
     * @param list<int> $extraEdgeIds
     */
    private function recomputeUserEdges(int $userId, DateTimeImmutable $now, array $extraEdgeIds = []): void
    {
        $window = $this->config->int('presence_window_days');
        $since  = $now->modify("-{$window} days")->format('Y-m-d');
        $ids    = $this->game->affectedEdgeIdsForUser($userId, $since);
        $ids    = array_values(array_unique(array_merge($ids, $extraEdgeIds)));
        sort($ids);
        foreach ($ids as $edgeId) {
            $this->recalc->recalculate($edgeId, $now);
        }
    }

    /** @return array<string,mixed> */
    private function crewPayload(int $crewId, bool $includeJoinCode): array
    {
        $crew    = $this->crews->crewById($crewId);
        if ($crew === null) {
            throw CrewException::notFound();
        }
        $members = $this->crews->members($crewId);
        $stats   = $this->game->meStats((int)$crew['claimant_id']);

        // captain_handle ist der *aktive* Captain (§12.2). Zeigt eine Captain-
        // Zeile auf einen gelöschten Account, gilt die Crew als captain-los
        // (null) → iOS blendet die Recovery-UI ein.
        $captainHandle = $this->crews->captainHandle($crewId);
        $captain = null;
        if ($captainHandle !== null) {
            foreach ($members as $m) {
                if ($m['role'] === 'captain' && $m['handle'] === $captainHandle) {
                    $captain = ['user_id' => $m['user_id'], 'handle' => $m['handle'], 'name' => $m['name']];
                    break;
                }
            }
        }

        $payload = [
            'id'              => (int)$crew['id'],
            'name'            => (string)$crew['name'],
            'slug'            => (string)$crew['slug'],
            'claimant_id'     => (int)$crew['claimant_id'],
            'member_count'    => count($members),
            'captain'         => $captain,
            'captain_handle'  => $captainHandle,
            'held_edges'      => $stats['held'],
            'pioneered_edges' => $stats['pioneered'],
            'held_length_m'   => $stats['held_length_m'],
            // Crew-Logo (GAME_CREW_LOGO_BACKEND.md §4): Cache-Buster bzw.
            // „Logo entfernen"-Sichtbarkeit. null = kein Logo.
            'logo_updated_at' => isset($crew['logo_updated_at']) && $crew['logo_updated_at'] !== null
                ? Clock::toIso8601(substr((string)$crew['logo_updated_at'], 0, 19))
                : null,
            'members'         => array_map(
                static fn (array $m): array => [
                    'user_id' => $m['user_id'],
                    'role'    => $m['role'],
                    'handle'  => $m['handle'],
                    'name'    => $m['name'],
                ],
                $members,
            ),
        ];

        // Stufe 3: Fraktion + Cooldown-Status (additiv).
        $payload['faction'] = null;
        if ($this->factions !== null && $crew['faction_id'] !== null) {
            $f = $this->factions->byId((int)$crew['faction_id']);
            if ($f !== null) {
                $payload['faction'] = ['key' => $f['key'], 'name' => $f['name'], 'color' => $f['color']];
            }
        }
        $payload['faction_change_allowed_at'] = $this->factionChangeAllowedAt(
            $crew['faction_joined_at'] !== null ? (string)$crew['faction_joined_at'] : null,
        );

        if ($includeJoinCode) {
            $payload['join_code'] = (string)$crew['join_code'];
        }
        return $payload;
    }

    /**
     * ISO-Zeitpunkt, ab dem ein Fraktionswechsel wieder erlaubt ist — null,
     * wenn neutral/nie gewechselt ODER der Cooldown bereits abgelaufen ist
     * (= sofort erlaubt).
     */
    private function factionChangeAllowedAt(?string $joinedAtMysql): ?string
    {
        $allowedAt = FactionService::changeAllowedAt(
            $joinedAtMysql,
            $this->config->int('faction_switch_cooldown_days'),
        );
        if ($allowedAt === null || $allowedAt <= Clock::nowUtc()) {
            return null;
        }
        return Clock::toIso8601($allowedAt->format('Y-m-d H:i:s'));
    }

    private function validateName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $len  = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($name === '') {
            throw CrewException::validation('Crew-Name erforderlich.');
        }
        if ($len > 40) {
            throw CrewException::validation('Crew-Name darf höchstens 40 Zeichen lang sein.');
        }
        return $name;
    }

    private function uniqueSlug(string $name): string
    {
        $base = strtolower($name);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if (strlen($base) < 3) {
            $base = 'crew-' . substr(bin2hex(random_bytes(4)), 0, 6);
        }
        $base = substr($base, 0, 40);
        $slug = $base;
        $i = 1;
        while ($this->crews->slugExists($slug)) {
            $i++;
            $suffix = '-' . $i;
            $slug = substr($base, 0, 40 - strlen($suffix)) . $suffix;
        }
        return $slug;
    }

    private function uniqueJoinCode(): string
    {
        do {
            $code = '';
            $max  = strlen(self::JOIN_CODE_ALPHABET) - 1;
            for ($i = 0; $i < 8; $i++) {
                $code .= self::JOIN_CODE_ALPHABET[random_int(0, $max)];
            }
        } while ($this->crews->joinCodeExists($code));
        return $code;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function transactional(callable $fn): mixed
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $fn();
            if ($own) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
