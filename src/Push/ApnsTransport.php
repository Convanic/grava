<?php
declare(strict_types=1);

namespace App\Push;

/**
 * Abstraktion über den APNs-Versand eines einzelnen Pushes. Erlaubt
 * eine echte HTTP/2-Implementierung in Produktion und einen Fake in
 * Tests.
 */
interface ApnsTransport
{
    /**
     * Sendet einen Push an genau ein Gerät.
     *
     * @param 'development'|'production'|string $environment APNs-Host-Wahl
     * @param string               $deviceToken Hex-Token
     * @param array<string,mixed>  $payload     vollständige APNs-Nutzlast (inkl. "aps")
     * @param string|null          $collapseId  optionale Apns-collapse-id
     *
     * @return int HTTP-Status von APNs (200 = ok, 410 = Token ungültig,
     *             0 = Transport-/Konfigurationsfehler / Versand übersprungen)
     */
    public function send(string $environment, string $deviceToken, array $payload, ?string $collapseId = null): int;
}
