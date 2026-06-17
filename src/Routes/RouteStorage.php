<?php
declare(strict_types=1);

namespace App\Routes;

use App\Config\Config;
use RuntimeException;

/**
 * Filesystem-Layer für hochgeladene Track-Payloads.
 *
 * Layout:
 *
 *     <STORAGE_ROUTES_DIR>/<user_id>/<route_public_id>/v<n>.<format>
 *
 * - Ein Verzeichnis pro Route, eine Datei pro Version.
 * - `payload_path` in der DB ist immer **relativ** zum
 *   STORAGE_ROUTES_DIR (z. B. `7/abc-…/v3.gpx`). Beim Lesen
 *   resolven wir gegen den absoluten Basis-Pfad und prüfen, dass
 *   das Ergebnis innerhalb der Basis liegt — Schutz gegen
 *   Path-Traversal (z. B. wenn jemand mit einem manipulierten
 *   `payload_path` in der DB durchkäme).
 * - Permissions: 0750 für Verzeichnisse, 0640 für Files. Damit
 *   ist der Webserver-User Schreibrecht hat, aber auf Multi-User-
 *   Hosts nicht jeder lesen kann.
 *
 * Bewusst KEINE eigene Transaktion: die DB-Schicht entscheidet,
 * wann persistiert wird; bei einem DB-Rollback räumt der Service-
 * Layer ({@see RouteService}) eine bereits geschriebene File
 * wieder weg.
 */
final class RouteStorage
{
    private readonly string $baseDir;

    public function __construct(Config $config)
    {
        $configured = (string)$config->get('STORAGE_ROUTES_DIR', '');
        if ($configured === '') {
            $configured = dirname(__DIR__, 2) . '/storage/routes';
        }
        // Wir resolven hier nicht — das Verzeichnis muss noch nicht
        // existieren. Wir legen es bei Bedarf in {@see save()} an.
        $this->baseDir = rtrim($configured, '/');
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * Schreibt eine Payload-Version nach Disk und liefert
     * Metadaten für die DB-Persistierung zurück.
     *
     * @return array{path:string, sha256:string, bytes:int}  payload_path ist relativ.
     */
    public function save(int $userId, string $routePublicId, int $version, string $format, string $payload): array
    {
        if ($userId <= 0)                                           { throw new RuntimeException('userId must be positive'); }
        if (!preg_match('/^[0-9a-f-]{36}$/', $routePublicId))       { throw new RuntimeException('routePublicId must be UUID v4 lowercase'); }
        if ($version < 1)                                            { throw new RuntimeException('version must be >= 1'); }
        if (!in_array($format, ['gpx', 'geojson'], true))            { throw new RuntimeException("format must be gpx|geojson, got {$format}"); }

        $relPath = sprintf('%d/%s/v%d.%s', $userId, $routePublicId, $version, $format);
        $abs     = $this->resolveAbs($relPath);

        $dir = dirname($abs);
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException("Cannot create route storage dir: {$dir}");
            }
        }

        // file_put_contents mit LOCK_EX vermeidet, dass zwei parallele
        // Requests sich beim Schreiben in die Quere kommen — auch wenn
        // wir das Schreiben pro Version eigentlich bereits durch die
        // Unique-Constraint (route_id, version) serialisieren.
        $written = @file_put_contents($abs, $payload, LOCK_EX);
        if ($written === false) {
            throw new RuntimeException("Cannot write route payload: {$abs}");
        }
        @chmod($abs, 0640);

        return [
            'path'   => $relPath,
            'sha256' => hash('sha256', $payload),
            'bytes'  => $written,
        ];
    }

    /**
     * Liefert den Inhalt einer hinterlegten Payload-Datei zurück.
     */
    public function load(string $relPath): string
    {
        $abs = $this->resolveAbs($relPath);
        if (!is_file($abs)) {
            throw new RuntimeException("Route payload missing: {$relPath}");
        }
        $contents = @file_get_contents($abs);
        if ($contents === false) {
            throw new RuntimeException("Cannot read route payload: {$relPath}");
        }
        return $contents;
    }

    /**
     * Löscht eine einzelne Version-Datei. Das Verzeichnis bleibt stehen
     * und wird vom Cleanup-Cron in Phase 7 mit aufgeräumt, sobald die
     * Route hart gelöscht wird.
     */
    public function deleteFile(string $relPath): void
    {
        $abs = $this->resolveAbs($relPath);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    /**
     * Löscht das gesamte Verzeichnis einer Route inklusive aller
     * Version-Files. Wird bei Hard-Delete im Cleanup gerufen.
     */
    public function deleteRouteDir(int $userId, string $routePublicId): void
    {
        if ($userId <= 0 || !preg_match('/^[0-9a-f-]{36}$/', $routePublicId)) {
            return;
        }
        $abs = $this->resolveAbs(sprintf('%d/%s', $userId, $routePublicId));
        if (!is_dir($abs)) {
            return;
        }
        // Erst Files, dann Dir.
        $entries = @scandir($abs);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                $sub = $abs . '/' . $entry;
                if (is_file($sub)) { @unlink($sub); }
            }
        }
        @rmdir($abs);
    }

    /**
     * Wandelt einen relativen Storage-Pfad in einen absoluten und
     * verifiziert, dass er nicht aus dem Storage-Wurzelverzeichnis
     * herausführt (Path-Traversal-Schutz).
     */
    private function resolveAbs(string $relPath): string
    {
        $relClean = ltrim($relPath, '/');
        if ($relClean === '' || str_contains($relClean, '..')) {
            throw new RuntimeException("Suspicious relative path: {$relPath}");
        }
        $abs = $this->baseDir . '/' . $relClean;

        // Prefix-Check muss auf realpath des baseDir basieren, damit
        // Symlinks nicht missbraucht werden können. baseDir muss
        // existieren — sonst ist auch nichts zu lesen.
        $realBase = realpath($this->baseDir);
        if ($realBase === false) {
            // Beim Schreiben legen wir das Dir in save() noch an.
            // realpath() kann dann gegen das Parent prüfen.
            return $abs;
        }
        $realAbs = realpath($abs);
        if ($realAbs !== false && !str_starts_with($realAbs, $realBase)) {
            throw new RuntimeException('Path traversal detected.');
        }
        return $abs;
    }
}
