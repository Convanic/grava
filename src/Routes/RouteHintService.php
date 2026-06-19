<?php
declare(strict_types=1);

namespace App\Routes;

/**
 * Orchestriert das Parsen und Persistieren der Wegpunkt-Hinweise.
 *
 * Dünne Schicht über {@see RouteHintParser} (GPX → ParsedHint) und
 * {@see RouteHintRepository} (DB). Wird beim Upload aus
 * {@see RouteService::createOrAddVersion()} aufgerufen und liefert die
 * Hinweise für die Ausgabe (Route-JSON, GeoJSON).
 */
final class RouteHintService
{
    public function __construct(
        private readonly RouteHintParser $parser,
        private readonly RouteHintRepository $repo,
    ) {}

    /**
     * Parst die Hinweise aus dem GPX-Payload und spiegelt sie auf die Route.
     * No-Op (löscht aber bestehende), wenn der Payload kein GPX ist bzw.
     * keine Hinweise enthält — die Route spiegelt immer den letzten Upload.
     */
    public function syncFromPayload(int $routeId, string $payload): void
    {
        $this->repo->sync($routeId, $this->parser->parse($payload));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForRoute(int $routeId): array
    {
        return $this->repo->listForRoute($routeId);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForPublicId(string $publicId): array
    {
        return $this->repo->listForPublicId($publicId);
    }
}
