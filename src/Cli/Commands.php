<?php
declare(strict_types=1);

namespace App\Cli;

use App\Auth\TokenService;
use App\Database\Migrator;

final class Commands
{
    public function __construct(
        private readonly string $basePath,
        private readonly TokenService $tokens,
    ) {}

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        switch ($command) {
            case 'cli:migrate':
            case 'migrate':
                return $this->migrate();

            case 'cron:cleanup':
            case 'cleanup':
                return $this->cleanup();

            case 'help':
            default:
                $this->help();
                return 0;
        }
    }

    private function migrate(): int
    {
        $migrator = new Migrator($this->basePath . '/migrations');
        $applied = $migrator->migrate();
        if (empty($applied)) {
            echo "Keine ausstehenden Migrationen.\n";
            return 0;
        }
        foreach ($applied as $name) {
            echo "Migriert: {$name}\n";
        }
        return 0;
    }

    private function cleanup(): int
    {
        $res = $this->tokens->cleanup();
        echo "Cleanup abgeschlossen:\n";
        foreach ($res as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        // L12: Auch ins Logfile schreiben — sonst sieht ein Operator
        // den Cron-Output nur, wenn er stdout in der crontab-Zeile
        // explizit umlenkt (`>> /var/log/...`). So bleibt zumindest
        // ein Eintrag pro Cleanup-Run im PHP-Errorlog.
        $summary = implode(', ', array_map(
            static fn($k, $v) => "{$k}={$v}",
            array_keys($res),
            array_values($res),
        ));
        error_log("cron:cleanup [{$summary}]");
        return 0;
    }

    private function help(): void
    {
        echo "GravelExplorer Backend CLI\n";
        echo "Nutzung: php public/index.php <befehl>\n\n";
        echo "Befehle:\n";
        echo "  cli:migrate      Wendet ausstehende Migrationen an\n";
        echo "  cron:cleanup     Löscht abgelaufene Tokens, Sessions, Verifizierungen, Rate-Limits\n";
        echo "  help             Zeigt diese Hilfe\n";
    }
}
