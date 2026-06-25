<?php
declare(strict_types=1);

namespace App\Presence;

use App\Game\GameConfig;
use App\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Live-Aktiv-Zähler: Heartbeat + TTL (PRESENCE_BACKEND.md).
 * Identität = u:{user_id} (Bearer) oder s:{session_id} (anonym).
 */
final class PresenceService
{
    private const HEARTBEAT_MIN_INTERVAL_S = 10;

    public function __construct(
        private readonly PresenceRepository $repo,
        private readonly GameConfig $config,
    ) {}

    /** @return array{active_count: int} */
    public function heartbeat(?int $userId, ?string $sessionId): array
    {
        $identity = $this->resolveIdentity($userId, $sessionId);
        if ($identity !== null && $this->shouldTrack($identity)) {
            $now = Clock::nowUtcString();
            if (!$this->isRateLimited($identity, $now)) {
                $this->repo->upsert($identity, $now);
            }
        }
        return ['active_count' => $this->activeCount()];
    }

    /** @return array{active_count: int} */
    public function stop(?int $userId, ?string $sessionId): array
    {
        $identity = $this->resolveIdentity($userId, $sessionId);
        if ($identity !== null) {
            $this->repo->delete($identity);
        }
        return ['active_count' => $this->activeCount()];
    }

    /** @return array{active_count: int} */
    public function active(): array
    {
        return ['active_count' => $this->activeCount()];
    }

    public function activeCount(): int
    {
        return $this->repo->countActive(
            $this->ttlSeconds(),
            $this->config->bool('presence_count_anonymous'),
        );
    }

    private function ttlSeconds(): int
    {
        $ttl = $this->config->int('presence_ttl_seconds');
        return $ttl > 0 ? $ttl : 180;
    }

    private function shouldTrack(string $identity): bool
    {
        if ($this->config->bool('presence_count_anonymous')) {
            return true;
        }
        return str_starts_with($identity, 'u:');
    }

    private function resolveIdentity(?int $userId, ?string $sessionId): ?string
    {
        if ($userId !== null && $userId > 0) {
            return 'u:' . $userId;
        }
        $sessionId = $this->normalizeSessionId($sessionId);
        if ($sessionId === null) {
            return null;
        }
        return 's:' . $sessionId;
    }

    private function normalizeSessionId(?string $sessionId): ?string
    {
        if ($sessionId === null || $sessionId === '') {
            return null;
        }
        $v = strtolower(trim($sessionId));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v)) {
            return null;
        }
        return $v;
    }

    private function isRateLimited(string $identity, string $now): bool
    {
        $lastSeen = $this->repo->findLastSeen($identity);
        if ($lastSeen === null) {
            return false;
        }
        $last = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $lastSeen, new DateTimeZone('UTC'));
        $cur  = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $now, new DateTimeZone('UTC'));
        if ($last === false || $cur === false) {
            return false;
        }
        return ($cur->getTimestamp() - $last->getTimestamp()) < self::HEARTBEAT_MIN_INTERVAL_S;
    }
}
