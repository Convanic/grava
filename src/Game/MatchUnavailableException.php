<?php
declare(strict_types=1);

namespace App\Game;

use RuntimeException;

/**
 * Wird geworfen, wenn das Map-Matching nicht durchführbar war — typischerweise
 * weil die Routing-Engine (Valhalla) nicht erreichbar ist oder keine
 * verwertbare Spur liefert.
 *
 * Eigener Typ (statt generischer RuntimeException), damit der HTTP-Adapter den
 * Routing-Ausfall sauber von echten Programmierfehlern/DB-Fehlern (PDOException
 * ist ebenfalls RuntimeException) unterscheiden und als 503 statt 500
 * beantworten kann.
 */
final class MatchUnavailableException extends RuntimeException
{
}
