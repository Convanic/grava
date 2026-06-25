<?php
declare(strict_types=1);

namespace App\Routes;

/**
 * Minimaler GPX-1.1-Export aus Track-Punkten (Strava-Upload).
 */
final class RouteGpxBuilder
{
    /**
     * @param list<array{lat:float,lon:float,ele:?float}> $points
     */
    public static function build(array $points, string $name): string
    {
        $name = self::xmlEscape(mb_substr(trim($name), 0, 140));
        $trkpts = [];
        foreach ($points as $p) {
            $lat = sprintf('%.6F', $p['lat']);
            $lon = sprintf('%.6F', $p['lon']);
            $ele = $p['ele'] !== null ? '<ele>' . sprintf('%.1F', $p['ele']) . '</ele>' : '';
            $trkpts[] = "<trkpt lat=\"{$lat}\" lon=\"{$lon}\">{$ele}</trkpt>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<gpx version="1.1" creator="GRAVA" xmlns="http://www.topografix.com/GPX/1/1">'
            . '<metadata><name>' . $name . '</name></metadata>'
            . '<trk><name>' . $name . '</name><trkseg>'
            . implode('', $trkpts)
            . '</trkseg></trk></gpx>';
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
