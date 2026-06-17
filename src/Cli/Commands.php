<?php
declare(strict_types=1);

namespace App\Cli;

use App\Auth\TokenService;
use App\Config\Config;
use App\Database\Migrator;
use App\Engagement\NotificationService;
use App\Heatmap\HeatmapService;
use App\Routes\RouteService;

final class Commands
{
    public function __construct(
        private readonly string $basePath,
        private readonly TokenService $tokens,
        private readonly RouteService $routes,
        private readonly Config $config,
        private readonly ?NotificationService $notifications = null,
        private readonly ?HeatmapService $heatmap = null,
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

            case 'cron:heatmap':
            case 'heatmap':
                return $this->rebuildHeatmap();

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
        // 1) Token-/Session-/Rate-Limit-Cleanup (M1).
        $tokenRes = $this->tokens->cleanup();

        // 2) M2 Phase 7: hard-delete soft-deleted routes nach Karenz.
        //    Default 30 Tage — kann via .env überstimmt werden.
        $graceDays  = $this->config->int('ROUTES_SOFT_DELETE_GRACE_DAYS', 30);
        $routesRes  = $this->routes->purgeSoftDeleted($graceDays);

        // 3) M4c: gelesene Notifications nach Karenz entfernen (Default 90 Tage).
        $notifDays  = $this->config->int('NOTIFICATIONS_READ_GRACE_DAYS', 90);
        $notifPurged = $this->notifications?->purgeOldRead($notifDays) ?? 0;

        // 4) M4e: verwaiste OAuth-States (abgebrochene Connects) entfernen.
        //    Werden bei erfolgreichem Callback single-use konsumiert;
        //    der Rest ist nach einer Stunde sicher tot.
        $statesPurged = 0;
        try {
            $stmt = \App\Database\Db::pdo()->prepare(
                'DELETE FROM oauth_states WHERE created_at <= (UTC_TIMESTAMP() - INTERVAL 1 HOUR)'
            );
            $stmt->execute();
            $statesPurged = $stmt->rowCount();
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), '1146')) {
                throw $e;
            }
        }

        $merged = [];
        foreach ($tokenRes as $k => $v) {
            $merged[$k] = $v;
        }
        foreach ($routesRes as $k => $v) {
            $merged['routes_' . $k] = $v;
        }
        // 5) M4f: Heatmap-Grid aus public Routen neu aggregieren.
        $heatmapCells = $this->heatmap?->rebuild() ?? 0;

        $merged['notifications_purged'] = $notifPurged;
        $merged['oauth_states_purged']  = $statesPurged;
        $merged['heatmap_cells']        = $heatmapCells;

        echo "Cleanup abgeschlossen:\n";
        foreach ($merged as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        // L12: Auch ins Logfile schreiben — sonst sieht ein Operator
        // den Cron-Output nur, wenn er stdout in der crontab-Zeile
        // explizit umlenkt (`>> /var/log/...`). So bleibt zumindest
        // ein Eintrag pro Cleanup-Run im PHP-Errorlog.
        $summary = implode(', ', array_map(
            static fn($k, $v) => "{$k}={$v}",
            array_keys($merged),
            array_values($merged),
        ));
        error_log("cron:cleanup [{$summary}]");
        return 0;
    }

    private function rebuildHeatmap(): int
    {
        if ($this->heatmap === null) {
            echo "HeatmapService nicht verfügbar.\n";
            return 1;
        }
        $cells = $this->heatmap->rebuild();
        echo "Heatmap neu aggregiert: {$cells} Zellen.\n";
        return 0;
    }

    private function help(): void
    {
        echo "GravelExplorer Backend CLI\n";
        echo "Nutzung: php public/index.php <befehl>\n\n";
        echo "Befehle:\n";
        echo "  cli:migrate      Wendet ausstehende Migrationen an\n";
        echo "  cron:cleanup     Löscht abgelaufene Tokens, Sessions, Verifizierungen, Rate-Limits + Heatmap-Rebuild\n";
        echo "  cron:heatmap     Aggregiert die Crowd-Heatmap aus public Routen neu\n";
        echo "  help             Zeigt diese Hilfe\n";
    }
}
