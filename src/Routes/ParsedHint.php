<?php
declare(strict_types=1);

namespace App\Routes;

use DateTimeImmutable;

/**
 * Ein aus dem GPX-Payload geparster Wegpunkt-Hinweis ({@see RouteHintParser}).
 *
 * Immutable Wert-Objekt — keine DB-IDs, kein client_hint_uuid (der wird erst
 * im {@see RouteHintRepository} deterministisch gebildet).
 */
final class ParsedHint
{
    public function __construct(
        public readonly string $reasonKey,
        /** @var 'negative'|'positive' */
        public readonly string $sentiment,
        public readonly string $label,
        public readonly ?string $note,
        public readonly float $lat,
        public readonly float $lon,
        public readonly ?DateTimeImmutable $recordedAt,
    ) {}
}
