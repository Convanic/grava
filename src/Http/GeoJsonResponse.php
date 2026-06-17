<?php
declare(strict_types=1);

namespace App\Http;

/**
 * Schlanke Antwort-Helfer für die same-origin-GeoJSON-Endpunkte, die
 * die Web-Karten füttern. Setzt den `application/geo+json`-MIME-Type
 * (RFC 7946) und beendet den Request — analog zu {@see Response}.
 */
final class GeoJsonResponse
{
    /**
     * @param array<string,mixed> $featureCollection
     */
    public static function emit(array $featureCollection, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/geo+json; charset=utf-8');
        // Karten-Geometrie darf der Browser kurz cachen — same-origin,
        // keine sensiblen Daten über die bereits geprüfte Sichtbarkeit
        // hinaus.
        header('Cache-Control: private, max-age=60');
        echo json_encode($featureCollection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(int $status): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['status' => $status]], JSON_UNESCAPED_SLASHES);
        exit;
    }
}
