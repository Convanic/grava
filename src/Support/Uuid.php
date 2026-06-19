<?php
declare(strict_types=1);

namespace App\Support;

use Ramsey\Uuid\Uuid as RUuid;

final class Uuid
{
    public static function v4(): string
    {
        return RUuid::uuid4()->toString();
    }

    /**
     * Deterministische UUIDv5 (SHA-1) aus einem Namen, im URL-Namespace.
     * Gleicher Name → gleiche UUID. Genutzt für idempotente, ohne
     * Client-UUID auskommende Schlüssel (z. B. Wegpunkt-Hinweise).
     */
    public static function v5(string $name): string
    {
        return RUuid::uuid5(RUuid::NAMESPACE_URL, $name)->toString();
    }
}
