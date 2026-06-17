<?php
declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(private readonly string $migrationsDir) {}

    /**
     * Apply all pending .sql files in lexical order.
     * Returns the list of applied filenames.
     *
     * @return string[]
     */
    public function migrate(): array
    {
        $pdo = Db::pdo();
        $this->ensureMigrationsTable($pdo);

        $files = glob(rtrim($this->migrationsDir, '/').'/*.sql') ?: [];
        // L11: natürliche Sortierung — sobald Migrationsnummern ohne führende
        // Nullen vorkommen (z.B. 0009 vs. 10), würde lexikalisches sort
        // 10 vor 9 setzen. natsort vermeidet das ohne führende-Null-Convention.
        natsort($files);
        $files = array_values($files);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if ($this->isApplied($pdo, $name)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Cannot read migration file: {$file}");
            }

            // DDL-Statements führen in MySQL implizite Commits aus, daher
            // KEINE explizite Transaktion verwenden. Beim Fehler einer
            // einzelnen Migration brechen wir komplett ab und der Nutzer
            // muss den Stand manuell prüfen.
            try {
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO migrations (name, ran_at) VALUES (?, UTC_TIMESTAMP())');
                $stmt->execute([$name]);
            } catch (\Throwable $e) {
                throw new RuntimeException("Migration failed ({$name}): " . $e->getMessage(), 0, $e);
            }

            $applied[] = $name;
        }

        return $applied;
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(191) NOT NULL,
                ran_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_migrations_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function isApplied(PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM migrations WHERE name = ? LIMIT 1');
        $stmt->execute([$name]);
        return (bool)$stmt->fetchColumn();
    }
}
