<?php
declare(strict_types=1);

namespace App\Routes;

use RuntimeException;

/**
 * Wirft der {@see GeometryParser} bei jeder Form von Eingabefehler —
 * unbekanntes Format, kaputtes XML/JSON, zu wenige Punkte, unsinnige
 * Lat/Lon-Werte.
 *
 * Eigene Klasse, damit der RouteController gezielt fangen und in
 * einen 422-Response mit `payload`-Field-Error umwandeln kann.
 */
final class GeometryParseException extends RuntimeException
{
}
