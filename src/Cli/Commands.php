<?php
declare(strict_types=1);

namespace App\Cli;

use App\Auth\TokenService;
use App\Config\Config;
use App\Database\Migrator;
use App\Engagement\NotificationService;
use App\Heatmap\HeatmapService;
use App\Heatmap\HeatmapLinesService;
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
        private readonly ?HeatmapLinesService $heatmapLines = null,
        private readonly ?\App\Game\GameRecomputeService $gameRecompute = null,
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

            case 'cron:heatmap-lines':
            case 'heatmap-lines':
                return $this->rebuildHeatmapLines();

            case 'heatmap:manifest':
                return $this->heatmapManifest();

            case 'heatmap:rebuild-local':
                return $this->rebuildHeatmapLinesLocal($argv);

            case 'heatmap:export-edges':
                return $this->exportHeatmapEdges($argv);

            case 'game:recompute':
                return $this->recomputeGame($argv);

            case 'internal:logtail':
            case 'logtail':
                return $this->logTail($argv);

            case 'internal:apns-check':
            case 'apns-check':
                return $this->apnsCheck();

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

    /** @param list<string> $argv */
    private function recomputeGame(array $argv): int
    {
        if ($this->gameRecompute === null) {
            echo "GameRecomputeService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $bbox = trim((string)($opts['bbox'] ?? ''));
        if ($bbox !== '') {
            $parts = array_map('trim', explode(',', $bbox));
            if (count($parts) !== 4 || array_filter($parts, static fn($p) => !is_numeric($p)) !== []) {
                echo "Nutzung: game:recompute --bbox=minLon,minLat,maxLon,maxLat\n";
                return 1;
            }
            [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
            $n = $this->gameRecompute->recomputeBbox($minLon, $minLat, $maxLon, $maxLat);
            echo "Spiel-Region neu berechnet: {$n} Kanten.\n";
            return 0;
        }
        $n = $this->gameRecompute->recomputeAll();
        echo "Spiel neu berechnet: {$n} Kanten.\n";
        return 0;
    }

    /**
     * Gibt die letzten Zeilen des PHP-Errorlogs (storage/logs/php.log) auf
     * stdout aus — read-only Diagnose ohne SSH. Wird per /internal/logtail
     * (token-geschützt) ausgelöst, z. B. um einen frischen PDO-Stacktrace
     * (SQLSTATE) nachzuschlagen.
     *
     * @param list<string> $argv
     */
    private function logTail(array $argv): int
    {
        $lines = (int)($argv[2] ?? 200);
        $lines = max(1, min(2000, $lines));
        $file  = $this->basePath . '/storage/logs/php.log';
        if (!is_file($file)) {
            echo "Kein Logfile vorhanden: {$file}\n";
            return 0;
        }
        // Effizient: nur das Dateiende lesen (bis ~512 KB), dann die
        // letzten N Zeilen ausschneiden. So bleibt der Endpoint auch bei
        // großen Logs bezahlbar.
        $maxBytes = 512 * 1024;
        $size     = (int)filesize($file);
        $fh       = fopen($file, 'rb');
        if ($fh === false) {
            echo "Logfile nicht lesbar: {$file}\n";
            return 1;
        }
        if ($size > $maxBytes) {
            fseek($fh, -$maxBytes, SEEK_END);
            fgets($fh); // angeschnittene erste Zeile verwerfen
        }
        $content = (string)stream_get_contents($fh);
        fclose($fh);

        $all  = preg_split("/\r\n|\n|\r/", rtrim($content, "\r\n"));
        $all  = $all === false ? [] : $all;
        $tail = array_slice($all, -$lines);
        echo "--- letzte " . count($tail) . " Zeilen aus storage/logs/php.log ---\n";
        echo implode("\n", $tail) . "\n";
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

    private function rebuildHeatmapLines(): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $res = $this->heatmapLines->rebuild();
        echo "Heatmap-Linien neu gematcht:\n";
        foreach ($res as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        return 0;
    }

    /**
     * Cutover-Hinweg (Modell A), PROD-seitig: gibt das Manifest der public
     * Routen als JSON auf stdout aus. Wird per /internal/heatmap/manifest
     * ausgelöst; der lokale `pull_prod_routes.sh` holt es per curl.
     */
    private function heatmapManifest(): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $routes = $this->heatmapLines->publicManifest();
        echo json_encode([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'count'        => count($routes),
            'routes'       => $routes,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        return 0;
    }

    /**
     * Cutover-Hinweg (Modell A), LOKAL: matcht die per SFTP geholten
     * Prod-Payloads gegen die lokale Valhalla und füllt heatmap_edges — ohne
     * dass die Prod-DB lokal vorliegen muss.
     *
     *   php public/index.php heatmap:rebuild-local \
     *       --manifest=build/heatmap_manifest.json --routes-dir=build/prod_routes
     */
    private function rebuildHeatmapLinesLocal(array $argv): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $manifestPath = (string)($opts['manifest'] ?? '');
        $routesDir    = (string)($opts['routes-dir'] ?? '');
        if ($manifestPath === '' || $routesDir === '') {
            echo "Nutzung: heatmap:rebuild-local --manifest=<datei.json> --routes-dir=<verzeichnis>\n";
            return 1;
        }
        if (!is_file($manifestPath)) {
            echo "Manifest nicht gefunden: {$manifestPath}\n";
            return 1;
        }
        $raw = (string)@file_get_contents($manifestPath);
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            echo "Manifest ist kein gültiges JSON: {$e->getMessage()}\n";
            return 1;
        }
        $entries = is_array($data['routes'] ?? null) ? $data['routes'] : [];
        if ($entries === []) {
            echo "Manifest enthält keine Routen.\n";
            return 1;
        }

        $res = $this->heatmapLines->rebuildFromManifest($entries, $routesDir);
        echo "Heatmap-Linien lokal aus Manifest gematcht:\n";
        foreach ($res as $k => $v) {
            echo "  {$k}: {$v}\n";
        }
        return 0;
    }

    /**
     * Cutover-Rückweg, LOKAL: schreibt die lokal berechneten heatmap_edges als
     * JSON nach --out. Diese Datei wird per scripts/push_heatmap_edges.sh an
     * /internal/heatmap/import auf PROD gepostet.
     *
     *   php public/index.php heatmap:export-edges --out=build/heatmap_edges.json
     */
    private function exportHeatmapEdges(array $argv): int
    {
        if ($this->heatmapLines === null) {
            echo "HeatmapLinesService nicht verfügbar.\n";
            return 1;
        }
        $opts = $this->parseOptions($argv);
        $out  = (string)($opts['out'] ?? '');
        if ($out === '') {
            echo "Nutzung: heatmap:export-edges --out=<datei.json>\n";
            return 1;
        }
        $rows = $this->heatmapLines->exportRows();
        $json = json_encode([
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'count'        => count($rows),
            'rows'         => $rows,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $dir = dirname($out);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@file_put_contents($out, $json) === false) {
            echo "Konnte nicht schreiben: {$out}\n";
            return 1;
        }
        echo "Export: " . count($rows) . " Kanten -> {$out}\n";
        return 0;
    }

    /**
     * APNs-Diagnose ohne SSH: prüft, ob der Server den .p8-Key tatsächlich
     * lesen und daraus ein Provider-JWT erzeugen kann. Gibt NIEMALS den Key
     * oder das JWT aus — nur Status-Flags. Pfad-Auflösung identisch zu
     * public/index.php (absolut oder relativ zum Projekt).
     */
    private function apnsCheck(): int
    {
        $enabled  = $this->config->bool('APNS_ENABLED', false);
        $keyId    = (string)($this->config->get('APNS_KEY_ID', '') ?? '');
        $teamId   = (string)($this->config->get('APNS_TEAM_ID', '98JR57G9M7') ?? '');
        $bundleId = (string)($this->config->get('APNS_BUNDLE_ID', 'world.grava.app') ?? '');
        $keyPath  = (string)($this->config->get('APNS_KEY_PATH', '') ?? '');

        $resolved = $keyPath === ''
            ? ''
            : (str_starts_with($keyPath, '/') ? $keyPath : $this->basePath . '/' . $keyPath);

        $exists   = $resolved !== '' && @is_file($resolved);
        $readable = $exists && @is_readable($resolved);
        $size     = $readable ? (int)@filesize($resolved) : 0;
        $pem      = $readable ? (string)(@file_get_contents($resolved) ?: '') : '';
        $looksPem = $pem !== '' && str_contains($pem, 'BEGIN PRIVATE KEY');

        $jwtOk = false;
        $jwtErr = null;
        if ($looksPem && $keyId !== '' && $teamId !== '') {
            try {
                \App\Push\ApnsJwt::provider($pem, $keyId, $teamId, time());
                $jwtOk = true;
            } catch (\Throwable $e) {
                $jwtErr = $e->getMessage();
            }
        }

        $usable = $enabled && $keyId !== '' && $teamId !== '' && $bundleId !== '' && $pem !== '';

        echo json_encode([
            'apns_enabled'   => $enabled,
            'key_id_set'     => $keyId !== '',
            'team_id'        => $teamId,
            'bundle_id'      => $bundleId,
            'key_path'       => $keyPath,
            'resolved_path'  => $resolved,
            'file_exists'    => $exists,
            'is_readable'    => $readable,
            'file_size'      => $size,
            'looks_like_p8'  => $looksPem,
            'jwt_mint_ok'    => $jwtOk,
            'jwt_error'      => $jwtErr,
            'config_usable'  => $usable,
            'verdict'        => $usable && $jwtOk
                ? 'OK — Push ist versandbereit.'
                : 'NICHT versandbereit — siehe Flags.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        echo "\n";
        return $usable && $jwtOk ? 0 : 1;
    }

    /**
     * Parst `--key=value`-Optionen aus argv (ab Index 2, hinter dem Befehl).
     *
     * @param list<string> $argv
     * @return array<string,string>
     */
    private function parseOptions(array $argv): array
    {
        $opts = [];
        foreach (array_slice($argv, 2) as $arg) {
            if (preg_match('/^--([a-z0-9\-]+)=(.*)$/i', (string)$arg, $m)) {
                $opts[$m[1]] = $m[2];
            }
        }
        return $opts;
    }

    private function help(): void
    {
        echo "GRAVA Backend CLI\n";
        echo "Nutzung: php public/index.php <befehl>\n\n";
        echo "Befehle:\n";
        echo "  cli:migrate         Wendet ausstehende Migrationen an\n";
        echo "  cron:cleanup        Löscht abgelaufene Tokens, Sessions, Verifizierungen, Rate-Limits + Heatmap-Rebuild\n";
        echo "  cron:heatmap        Aggregiert die Crowd-Heatmap (Centroids) aus public Routen neu\n";
        echo "  cron:heatmap-lines  Map-Matching der public Routen -> heatmap_edges (Streckenlinien)\n";
        echo "  heatmap:manifest    (PROD) Gibt das Manifest der public Routen als JSON aus (Cutover-Hinweg)\n";
        echo "  heatmap:rebuild-local  (LOKAL) Rebuild aus Manifest + Dateien: --manifest=.. --routes-dir=..\n";
        echo "  heatmap:export-edges   (LOKAL) heatmap_edges als JSON exportieren: --out=..\n";
        echo "  game:recompute      Berechnet alle Spiel-Kanten aus den Pässen neu [--bbox=minLon,minLat,maxLon,maxLat]\n";
        echo "  help                Zeigt diese Hilfe\n";
    }
}
