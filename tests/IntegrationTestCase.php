<?php
declare(strict_types=1);

namespace Tests;

use App\Database\Db;
use App\Support\Clock;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Basis für DB-gestützte Tests.
 *
 * Isolation per TRUNCATE in setUp(): jeder Test startet auf einer
 * leeren Datenbank (Migrationen bleiben erhalten). TRUNCATE statt
 * Transaktions-Rollback, weil mehrere Services eigene Transaktionen
 * öffnen — verschachtelte Transaktionen wären in MySQL fragil.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('GE_TEST_DB_READY') || GE_TEST_DB_READY !== true) {
            $this->markTestSkipped('Keine Test-DB verfügbar.');
        }
        $this->pdo = Db::pdo();
        $this->truncateAll();
    }

    private function truncateAll(): void
    {
        $tables = $this->pdo->query(
            'SELECT table_name FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = "BASE TABLE"'
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            if ($t === 'migrations') {
                continue; // Schema-Stand erhalten
            }
            $this->pdo->exec('TRUNCATE TABLE `' . $t . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Referenz-Seed (Stufe 3) nach dem TRUNCATE wiederherstellen — analog
        // zum Migrations-Seed; ohne ihn schlügen Fraktions-Tests fehl.
        try {
            $this->pdo->exec(
                "INSERT INTO game_faction (key_slug, name, color_hex) VALUES
                    ('green', 'Grün', '#2EA043'),
                    ('blue',  'Blau', '#1F6FEB')
                 ON DUPLICATE KEY UPDATE key_slug = key_slug"
            );
        } catch (\PDOException) {
            // Tabelle existiert (noch) nicht — Schema vor Migration 0019.
        }
    }

    // ---------------------------------------------------------------
    // Seed-Helfer
    // ---------------------------------------------------------------

    /**
     * Legt einen aktiven, verifizierten User an und liefert dessen
     * interne ID.
     */
    protected function createUser(?string $handle = null, ?string $email = null): int
    {
        $now = Clock::nowUtcString();
        $email ??= 'u' . bin2hex(random_bytes(4)) . '@test.local';
        $stmt = $this->pdo->prepare(
            'INSERT INTO users
                (public_id, email, email_verified_at, password_hash, public_handle,
                 display_name, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, "active", ?, ?)'
        );
        $stmt->execute([
            self::uuid4(),
            $email,
            $now,
            password_hash('irrelevant-for-tests', PASSWORD_BCRYPT),
            $handle,
            $handle ?? 'Test User',
            $now,
            $now,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Legt eine Route (mit Centroid) an und liefert die public_id.
     */
    protected function createRoute(
        int $ownerId,
        string $visibility = 'public',
        float $lat = 49.5,
        float $lon = 8.5,
        bool $deleted = false,
    ): string {
        $now = Clock::nowUtcString();
        $publicId = self::uuid4();
        $stmt = $this->pdo->prepare(
            'INSERT INTO routes
                (public_id, user_id, title, visibility, source, centroid,
                 created_at, updated_at, deleted_at)
             VALUES (?, ?, ?, ?, "app", ST_SRID(POINT(?, ?), 4326), ?, ?, ?)'
        );
        $stmt->execute([
            $publicId,
            $ownerId,
            'Test Route',
            $visibility,
            $lon,   // POINT(lon, lat)
            $lat,
            $now,
            $now,
            $deleted ? $now : null,
        ]);
        return $publicId;
    }

    protected function block(int $blockerId, int $blockedId): void
    {
        $this->pdo->prepare(
            'INSERT INTO user_blocks (blocker_id, blocked_id, created_at)
             VALUES (?, ?, ?)'
        )->execute([$blockerId, $blockedId, Clock::nowUtcString()]);
    }

    protected static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
