<?php
declare(strict_types=1);

namespace App\Heatmap;

use RuntimeException;

/**
 * Signalisiert, dass die Valhalla-Engine NICHT erreichbar war — echter
 * Transport-/Verbindungsfehler oder ein 5xx der Engine.
 *
 * Bewusst abgegrenzt von einem „kein Match"-Ergebnis: Antwortet die Engine mit
 * 4xx (z. B. `error_code 444` „map_snap failed") oder 200 ohne verwertbare
 * Kanten, liefert {@see ValhallaClient::matchTrace()} `null` (kein Wurf) — die
 * Engine läuft, die Spur ließ sich nur nicht matchen. Nur die echte
 * Unerreichbarkeit ist ein retrybarer Zustand (→ HTTP 503 routing_unavailable).
 */
final class ValhallaUnavailableException extends RuntimeException
{
}
