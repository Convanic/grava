<?php
declare(strict_types=1);

namespace App\Routes;

use App\Database\Db;
use App\Support\Uuid;
use RuntimeException;
use Throwable;

/**
 * Geschäftslogik für Routen — Upload, Versionierung, Listing,
 * Patch, Soft-Delete, Download.
 *
 * Kern-Operation ist {@see createOrAddVersion()}: idempotent über
 * `client_route_uuid`. Schickt der Client denselben UUID erneut,
 * legt der Server eine neue Version an, statt eine zweite Route.
 *
 * Persistenz-Reihenfolge bei Erfolg:
 *  1. **Parser + Stats** (kein Side-Effect, kann scheitern → 422)
 *  2. **DB-Transaktion**:
 *     a) bei neuer Route: `INSERT routes` (mit head=NULL)
 *     b) `INSERT route_versions`
 *     c) `UPDATE routes SET head_version_id = …, denormalisierte Stats`
 *     d) Tags ersetzen
 *  3. **File schreiben** _nach_ DB-Commit. Schlägt das Schreiben fehl,
 *     löschen wir den DB-Datensatz wieder (route_id und route_version_id
 *     stehen ja schon fest). So vermeiden wir verwaiste Files (FS-only
 *     ohne DB-Eintrag), die den Storage-Layer aufblähen würden.
 *
 * Frühere Variante hatte File-Write _vor_ DB. Der Trade-off: bei
 * DB-Crash hat man Junk im FS. Mit File-Write _nach_ DB-Commit hat
 * man bei FS-Crash einen DB-Eintrag, der auf eine fehlende Datei zeigt.
 * Beide Fälle sind ungut, aber FS-Cleanup ist mit dem
 * `payload_path = NULL`-Pattern + Cleanup-Cron einfacher zu reparieren.
 *
 * Wir wählen explizit den File-Schreibfehler-Pfad mit Rollback (DB
 * schreiben, dann File schreiben, bei Fehler beide Datensätze
 * löschen — das ist atomarer aus Anwender-Sicht).
 */
final class RouteService
{
    public function __construct(
        private readonly RouteRepository $routes,
        private readonly RouteStorage $storage,
        private readonly GeometryParser $parser,
        private readonly GeometryStats $stats,
        // M8: optional — parst/persistiert Wegpunkt-Hinweise aus dem
        // GPX-Payload und liefert sie für die Ausgabe. Nullable, damit
        // bestehende Aufrufer/Tests RouteService ohne Hinweise bauen können.
        private readonly ?RouteHintService $hints = null,
    ) {}

    /**
     * Anlegen einer Route oder Anhängen einer neuen Version.
     *
     * @param list<string> $tags
     * @return array{
     *   route: array<string,mixed>,
     *   action: 'created'|'added_version',
     *   version: int
     * }
     */
    public function createOrAddVersion(
        int $userId,
        string $title,
        ?string $description,
        string $visibility,
        string $source,
        ?string $clientRouteUuid,
        string $payload,
        array $tags = [],
    ): array {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 140) {
            throw new RuntimeException('Title must be 1..140 chars.');
        }
        if ($description !== null && mb_strlen($description) > 8000) {
            throw new RuntimeException('Description max 8000 chars.');
        }
        if ($payload === '') {
            throw new GeometryParseException('Payload ist leer.');
        }

        $parsed = $this->parser->parse($payload);
        $stats  = $this->stats->compute($parsed);

        $existing = null;
        if ($clientRouteUuid !== null && $clientRouteUuid !== '') {
            $existing = $this->routes->findByClientUuid($userId, $clientRouteUuid);
            if ($existing !== null && $existing['deleted_at'] !== null) {
                // Soft-deleted Routen werden nicht "wiederbelebt" — wir
                // lassen den Client einen frischen UUID schicken, sonst
                // hätten wir einen Replay einer gelöschten Route.
                throw new RuntimeException('Route mit diesem client_route_uuid wurde gelöscht.');
            }
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        $isNew         = $existing === null;
        $routeId       = $existing['id']        ?? 0;
        $routePublicId = $existing['public_id'] ?? Uuid::v4();
        $newVersion    = 1;

        try {
            if ($isNew) {
                $routeId = $this->routes->insertRouteShell(
                    userId: $userId,
                    publicId: $routePublicId,
                    clientRouteUuid: $clientRouteUuid,
                    title: $title,
                    description: $description,
                    visibility: $visibility,
                    source: $source,
                    initialStats: $stats,
                );
                $newVersion = 1;
            } else {
                $newVersion = $this->routes->nextVersion($routeId);
            }

            // 2) Wir wissen jetzt routeId + newVersion. Datei schreiben — VOR
            //    dem INSERT route_versions, weil wir die Path/SHA/Bytes für
            //    den Insert brauchen. Bei FS-Fehler werfen wir aus dem
            //    Catch raus → Rollback → ggf. Junk-Dir bleibt, aber kein
            //    DB-Inconsistency.
            $saved = $this->storage->save($userId, $routePublicId, $newVersion, $parsed->sourceFormat, $payload);

            $versionId = $this->routes->insertVersion(
                routeId: $routeId,
                version: $newVersion,
                format: $parsed->sourceFormat,
                payloadPath: $saved['path'],
                sha256: $saved['sha256'],
                bytes: $saved['bytes'],
                stats: $stats,
            );

            $this->routes->updateRouteHead($routeId, $versionId, $stats);

            // M8: Wegpunkt-Hinweise aus dem GPX-Payload spiegeln. Nur für
            // GPX — GeoJSON kann keine <wpt>-Hinweise tragen, ein
            // GeoJSON-Re-Upload soll bestehende Hinweise also nicht löschen.
            if ($parsed->sourceFormat === 'gpx') {
                $this->hints?->syncFromPayload($routeId, $payload);
            }

            if (!$isNew) {
                // PATCH-artiges Verhalten: bei Re-Upload aktualisieren wir
                // auch Title/Description/Visibility, weil der Client
                // nochmal alle Metadaten geschickt hat. Geometrie ist
                // immutable je Version, aber Metadaten leben am Route-
                // Datensatz, nicht an der Version.
                $this->routes->updateRouteMeta($routeId, $title, $description, $visibility);
            }

            if ($tags !== []) {
                $this->routes->replaceTags($routeId, $tags);
            } elseif ($isNew) {
                // bei neuem Datensatz reichen wir leeres Tag-Array durch
                $this->routes->replaceTags($routeId, []);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            // Versuche, die ggf. geschriebene Datei zu putzen. Pfad ist
            // deterministisch, also können wir das auch ohne $saved tun.
            $this->storage->deleteFile(sprintf('%d/%s/v%d.%s', $userId, $routePublicId, $newVersion, $parsed->sourceFormat));
            throw $e;
        }

        $route = $this->routes->findByPublicId($routePublicId, $userId);
        if ($route === null) {
            // Sollte nicht passieren — direkt nach Commit.
            throw new RuntimeException('Route konnte nach Anlage nicht gelesen werden.');
        }
        $route['tags'] = $this->routes->listTags($routeId);

        return [
            'route'   => self::stripInternal($route),
            'action'  => $isNew ? 'created' : 'added_version',
            'version' => $newVersion,
        ];
    }

    /**
     * Aktualisiert nur Metadaten — Geometrie bleibt unverändert.
     *
     * @param array{title?:string, description?:?string, visibility?:string, tags?:list<string>} $patch
     * @return array<string,mixed> public form
     */
    public function updateMeta(int $userId, string $publicId, array $patch): array
    {
        $existing = $this->routes->findByPublicId($publicId, $userId);
        if ($existing === null) {
            throw new RouteNotFoundException();
        }
        $routeId = (int)$existing['_internal']['route_id'];

        $title       = (string)($patch['title']      ?? $existing['title']);
        $description = array_key_exists('description', $patch) ? $patch['description'] : $existing['description'];
        $visibility  = (string)($patch['visibility'] ?? $existing['visibility']);

        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 140) {
            throw new RuntimeException('Title must be 1..140 chars.');
        }
        if ($description !== null && mb_strlen((string)$description) > 8000) {
            throw new RuntimeException('Description max 8000 chars.');
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $this->routes->updateRouteMeta($routeId, $title, is_string($description) ? $description : null, $visibility);
            if (array_key_exists('tags', $patch) && is_array($patch['tags'])) {
                $this->routes->replaceTags($routeId, $patch['tags']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $fresh = $this->routes->findByPublicId($publicId, $userId);
        if ($fresh === null) {
            throw new RuntimeException('Route nach Update verschwunden.');
        }
        $fresh['tags'] = $this->routes->listTags($routeId);
        return self::stripInternal($fresh);
    }

    /**
     * Soft-Delete. Datei bleibt erst mal liegen — Phase 7 Cleanup-Cron
     * räumt nach Karenz-Zeitraum (Default 30 Tage) hart auf.
     */
    public function softDelete(int $userId, string $publicId): void
    {
        $existing = $this->routes->findByPublicId($publicId, $userId);
        if ($existing === null) {
            throw new RouteNotFoundException();
        }
        $this->routes->softDelete((int)$existing['_internal']['route_id']);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        $items = $this->routes->listForUser($userId, $limit, $offset);
        $result = [];
        foreach ($items as $item) {
            $item['tags'] = $this->routes->listTags((int)$item['_internal']['route_id']);
            $result[] = self::stripInternal($item);
        }
        return $result;
    }

    /**
     * Holt eine einzelne Route in Owner-Sicht.
     *
     * @return array<string,mixed>|null
     */
    public function get(int $userId, string $publicId): ?array
    {
        $route = $this->routes->findByPublicId($publicId, $userId);
        if ($route === null) {
            return null;
        }
        $routeId = (int)$route['_internal']['route_id'];
        $route['tags']  = $this->routes->listTags($routeId);
        $route['hints'] = $this->hints?->listForRoute($routeId) ?? [];
        return self::stripInternal($route);
    }

    /**
     * Wegpunkt-Hinweise einer Route über die öffentliche UUID — ohne
     * Owner-Check (Sichtbarkeit muss der Aufrufer geprüft haben). Gedacht
     * für die GeoJSON-Endpunkte (eigene Karte, Share, fremdes Profil).
     *
     * @return list<array<string,mixed>>
     */
    public function hintsForPublicId(string $publicId): array
    {
        return $this->hints?->listForPublicId($publicId) ?? [];
    }

    /**
     * Lädt die Payload-Datei einer Version (Default = head). Owner-
     * Check inklusive.
     *
     * @return array{format:string, payload:string, version:int}
     */
    public function loadPayload(int $userId, string $publicId, ?int $version = null): array
    {
        $route = $this->routes->findByPublicId($publicId, $userId);
        if ($route === null) {
            throw new RouteNotFoundException();
        }
        $routeId = (int)$route['_internal']['route_id'];
        $ver = $this->routes->findVersion($routeId, $version);
        if ($ver === null) {
            throw new RouteNotFoundException();
        }
        return [
            'format'  => $ver['format'],
            'payload' => $this->storage->load($ver['payload_path']),
            'version' => $ver['version'],
        ];
    }

    /**
     * Lädt die Payload-Datei einer Route **ohne Owner-Check**, nur über
     * die public UUID. Gedacht für Aufrufer, die die Sichtbarkeit bereits
     * anderweitig validiert haben (öffentliche Profil-Route, Share-Token).
     * NIEMALS direkt an einen Owner-geschützten Endpunkt hängen —
     * dafür gibt es {@see loadPayload()}.
     *
     * @return array{format:string, payload:string, version:int}
     */
    public function loadPayloadByPublicId(string $publicId, ?int $version = null): array
    {
        $route = $this->routes->findByPublicId($publicId);
        if ($route === null) {
            throw new RouteNotFoundException();
        }
        $routeId = (int)$route['_internal']['route_id'];
        $ver = $this->routes->findVersion($routeId, $version);
        if ($ver === null) {
            throw new RouteNotFoundException();
        }
        return [
            'format'  => $ver['format'],
            'payload' => $this->storage->load($ver['payload_path']),
            'version' => $ver['version'],
        ];
    }

    /**
     * Hart-Löschen aller Routen, die seit mindestens `$graceDays` Tagen
     * soft-gelöscht sind. Dabei werden:
     *  1. Die DB-Datensätze entfernt (CASCADE räumt route_versions,
     *     route_tags und route_shares automatisch mit weg).
     *  2. Das gesamte FS-Verzeichnis der Route gelöscht.
     *
     * Aufrufer ist die `cron:cleanup`-CLI; läuft typischerweise
     * einmal täglich. Das Löschen ist idempotent — fehlende Files
     * werden geschluckt, fehlende DB-Datensätze werden im nächsten
     * Lauf einfach nicht mehr gefunden.
     *
     * @return array{candidates:int, deleted:int, fs_dirs:int}
     */
    public function purgeSoftDeleted(int $graceDays): array
    {
        $candidates = $this->routes->findHardDeleteCandidates($graceDays);
        $deleted = 0;
        $dirs    = 0;

        foreach ($candidates as $c) {
            // Erst FS, dann DB. Falls FS-rmdir fehlschlägt (Permissions),
            // bleibt der DB-Eintrag — der nächste Lauf versucht es
            // erneut. Andersrum (DB weg, FS Junk) wäre unhilflich,
            // weil der Cleanup keine Verbindung mehr zum verwaisten
            // Dir hat.
            $hadDir = is_dir($this->storage->baseDir() . '/' . $c['user_id'] . '/' . $c['public_id']);
            $this->storage->deleteRouteDir($c['user_id'], $c['public_id']);
            if ($hadDir) { $dirs++; }
            $this->routes->hardDelete($c['id']);
            $deleted++;
        }

        return [
            'candidates' => count($candidates),
            'deleted'    => $deleted,
            'fs_dirs'    => $dirs,
        ];
    }

    /**
     * @param array<string,mixed> $route
     * @return array<string,mixed>
     */
    private static function stripInternal(array $route): array
    {
        unset($route['_internal']);
        return $route;
    }
}
