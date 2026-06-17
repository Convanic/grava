<?php
declare(strict_types=1);

namespace App\Routes;

use RuntimeException;

/**
 * Wird vom RouteService geworfen, wenn ein Pfad eine Route per
 * `public_id` adressiert, die entweder nicht existiert oder nicht
 * dem aufrufenden User gehört.
 *
 * Bewusst nicht differenziert ("nicht da" vs "nicht dein"): das
 * würde sonst eine ID-Probing-Lücke öffnen.
 *
 * Controller fängt das gezielt und antwortet mit 404.
 */
final class RouteNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Route not found.');
    }
}
