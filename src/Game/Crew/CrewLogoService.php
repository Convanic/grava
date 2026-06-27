<?php
declare(strict_types=1);

namespace App\Game\Crew;

use App\Config\Config;
use App\Support\Clock;

/**
 * Crew-Logo: Upload, Storage und Serving (GAME_CREW_LOGO_BACKEND.md).
 *
 * 1:1-Spiegel des Avatar-Mechanismus (App\Media\AvatarService), nur pro Crew
 * statt pro User: nur der Captain darf schreiben, alle sehen es. Das fertig
 * prozessierte JPEG liegt unter <STORAGE_CREW_LOGOS_DIR>/<crew_id>.jpg;
 * game_crew.logo_path ist relativ zur Basis (z. B. "42.jpg"). Ausgeliefert
 * wird ausschließlich über die Logo-Route (kein Directory-Listing).
 *
 * Bildverarbeitung: Eingang JPEG/PNG, serverseitig per getimagesize()
 * validiert (kein Vertrauen auf den Content-Type-Header), quadratisch
 * zentriert zugeschnitten, auf max. 512×512 skaliert, als JPEG (q≈0.85)
 * re-encodiert (strippt EXIF/Metadaten).
 */
final class CrewLogoService
{
    public const MAX_DIM   = 512;
    public const MAX_BYTES = 8 * 1024 * 1024; // 8 MB (GAME_CREW_LOGO_BACKEND.md §2)

    private readonly string $baseDir;

    public function __construct(
        private readonly CrewRepository $crews,
        Config $config,
    ) {
        $configured = (string)$config->get('STORAGE_CREW_LOGOS_DIR', '');
        if ($configured === '') {
            $configured = dirname(__DIR__, 3) . '/storage/crew-logos';
        }
        $this->baseDir = rtrim($configured, '/');
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * Captain lädt das Logo hoch/ersetzt es. Validiert + normalisiert das Bild,
     * speichert es und setzt logo_path + logo_updated_at.
     *
     * @param array{tmp_name:string, size:int, type:string, name:string} $upload
     * @return array{logo_path:string, logo_updated_at:string} ISO-8601-Zeit.
     */
    public function store(string $slug, int $userId, array $upload): array
    {
        $crew   = $this->captainCrew($slug, $userId);
        $crewId = (int)$crew['id'];

        $tmp  = $upload['tmp_name'];
        $size = (int)$upload['size'];
        if ($size <= 0 || !is_readable($tmp)) {
            throw CrewLogoException::required();
        }
        if ($size > self::MAX_BYTES) {
            throw CrewLogoException::tooLarge();
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            throw CrewLogoException::unsupportedType();
        }
        $imageType = (int)$info[2];
        if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw CrewLogoException::unsupportedType();
        }

        if (!is_dir($this->baseDir) && !@mkdir($this->baseDir, 0750, true) && !is_dir($this->baseDir)) {
            throw CrewLogoException::storeFailed();
        }

        $relPath = $crewId . '.jpg';
        $abs     = $this->baseDir . '/' . $relPath;
        $this->processToJpeg($tmp, $imageType, $abs);

        $now = Clock::nowUtc()->format('Y-m-d H:i:s.v');
        $this->crews->setLogo($crewId, $relPath, $now);

        return [
            'logo_path'       => '/storage/crew-logos/' . $relPath,
            'logo_updated_at' => Clock::toIso8601(substr($now, 0, 19)),
        ];
    }

    /**
     * Captain entfernt das Logo (File + DB-Spalten). Idempotent: ohne Logo
     * passiert nichts außer dem (no-op) DB-Reset.
     */
    public function delete(string $slug, int $userId): void
    {
        $crew   = $this->captainCrew($slug, $userId);
        $crewId = (int)$crew['id'];

        $rel = $crew['logo_path'] ?? null;
        if (is_string($rel) && $rel !== '' && !str_contains($rel, '..')) {
            $abs = $this->baseDir . '/' . ltrim($rel, '/');
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->crews->clearLogo($crewId);
    }

    /**
     * Öffentliches Serving: liefert den absoluten Dateipfad, falls die Crew ein
     * Logo gesetzt hat und die Datei existiert. Sonst null → der Controller
     * antwortet mit 404 (die App zeigt dann ihren eigenen Platzhalter).
     *
     * @return array{path:string, mime:string}|null
     */
    public function resolveForSlug(string $slug): ?array
    {
        $crew = $this->crews->crewBySlug(trim($slug));
        if ($crew === null) {
            return null;
        }
        $rel = $crew['logo_path'] ?? null;
        if (!is_string($rel) || $rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $abs = $this->baseDir . '/' . ltrim($rel, '/');
        if (!is_file($abs)) {
            return null;
        }
        return ['path' => $abs, 'mime' => 'image/jpeg'];
    }

    /**
     * Verifiziert Crew-Existenz und dass der User der Captain dieser Crew ist.
     * Unbekannter Slug → 404; Nicht-Mitglied/Nicht-Captain → 403.
     *
     * @return array<string,mixed>
     */
    private function captainCrew(string $slug, int $userId): array
    {
        $crew = $this->crews->crewBySlug(trim($slug));
        if ($crew === null) {
            throw CrewLogoException::notFound();
        }
        $membership = $this->crews->membershipOf($userId);
        if ($membership === null
            || $membership['crew_id'] !== (int)$crew['id']
            || $membership['role'] !== 'captain'
        ) {
            throw CrewLogoException::forbidden();
        }
        return $crew;
    }

    /**
     * Schneidet das Bild quadratisch zentriert zu, skaliert auf max. MAX_DIM
     * und schreibt es als JPEG (strippt dabei EXIF). Ohne GD wird das Original
     * als best-effort-Kopie abgelegt.
     */
    private function processToJpeg(string $tmp, int $imageType, string $abs): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            if (!@copy($tmp, $abs)) {
                throw CrewLogoException::storeFailed();
            }
            @chmod($abs, 0640);
            return;
        }

        $src = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            default        => false,
        };
        if ($src === false) {
            throw CrewLogoException::unsupportedType();
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $srcX = (int)(($w - $side) / 2);
        $srcY = (int)(($h - $side) / 2);
        $dim  = min(self::MAX_DIM, $side);

        $dst   = imagecreatetruecolor($dim, $dim);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $dim, $dim, $white);
        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $dim, $dim, $side, $side);
        imagedestroy($src);

        $ok = imagejpeg($dst, $abs, 85);
        imagedestroy($dst);
        if ($ok === false) {
            throw CrewLogoException::storeFailed();
        }
        @chmod($abs, 0640);
    }
}
