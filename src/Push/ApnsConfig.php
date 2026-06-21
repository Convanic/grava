<?php
declare(strict_types=1);

namespace App\Push;

/**
 * APNs-Konfiguration (Token-based Auth, .p8-Key). Siehe
 * backend/PUSH_BACKEND.md: Topic = Bundle-ID, Team-ID 98JR57G9M7.
 */
final class ApnsConfig
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $keyId,
        public readonly string $teamId,
        public readonly string $bundleId,
        /** PEM-Inhalt des .p8-Keys (ECDSA P-256 Private Key). */
        public readonly string $keyPem,
    ) {}

    /** Vollständig konfiguriert (Versand möglich)? */
    public function usable(): bool
    {
        return $this->enabled
            && $this->keyId !== ''
            && $this->teamId !== ''
            && $this->bundleId !== ''
            && $this->keyPem !== '';
    }
}
