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
        // M1: Atomares Inkrement + Read-back. Der LAST_INSERT_ID-Trick
        // schiebt den neuen Counter-Wert ins per-connection
        // LAST_INSERT_ID-Register, das wir mit lastInsertId() lesen —
        // damit ist „erhöhen und neuen Wert lesen" eine Operation, ohne
        // Race-Window zwischen INSERT und SELECT.
        // Bei initial-INSERT (Row noch nicht da) liefert lastInsertId()
        // automatisch die neue Auto-Increment-ID; deshalb verwenden wir
        // beim INSERT explizit `1` und beim UPDATE den Trick.
        $stmt = $pdo->prepare(
            'INSERT INTO rate_limits (action, identifier, window_start, count)
             VALUES (?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(count + 1)'
        );
        $stmt->execute([$action, substr($identifier, 0, 254), $windowStart]);

        $rowsAffected = $stmt->rowCount();
        if ($rowsAffected === 1) {
            // Frische Zeile angelegt → Counter steht auf 1.
            $count = 1;
        } else {
            // ON DUPLICATE KEY UPDATE wurde ausgeführt → lastInsertId
            // enthält den neuen count-Wert.
            $count = (int)$pdo->lastInsertId();
        }

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
