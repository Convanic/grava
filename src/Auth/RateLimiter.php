<?php
declare(strict_types=1);

namespace App\Auth;

use App\Config\Config;
use App\Database\Db;
use App\Support\Clock;

/**
 * Fixed-window counter rate limiter (per action + identifier).
 *
 * The window is RATE_WINDOW_SECONDS wide and aligned to the configured
 * boundary (e.g. every 15 min on the wall clock). Hot path uses INSERT
 * ... ON DUPLICATE KEY UPDATE so it does not require a transaction.
 */
final class RateLimiter
{
    public function __construct(private readonly Config $config) {}

    /**
     * Increment the counter and return true if the limit is exceeded.
     * On hit, sets a Retry-After header and writes the standard 429 envelope is the caller's job.
     */
    public function hit(string $action, string $identifier, int $max): bool
    {
        $windowSeconds = max(60, $this->config->int('RATE_WINDOW_SECONDS', 900));
        $now = time();
        $windowStartTs = $now - ($now % $windowSeconds);
        $windowStart = gmdate('Y-m-d H:i:s', $windowStartTs);

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (action, identifier, window_start, count)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1'
        );
        $stmt->execute([$action, substr($identifier, 0, 254), $windowStart]);

        $sel = $pdo->prepare('SELECT count FROM rate_limits WHERE action = ? AND identifier = ? AND window_start = ?');
        $sel->execute([$action, substr($identifier, 0, 254), $windowStart]);
        $count = (int)$sel->fetchColumn();

        return $count > $max;
    }

    public function retryAfter(): int
    {
        $windowSeconds = max(60, $this->config->int('RATE_WINDOW_SECONDS', 900));
        $now = time();
        $windowStartTs = $now - ($now % $windowSeconds);
        return max(1, ($windowStartTs + $windowSeconds) - $now);
    }
}
