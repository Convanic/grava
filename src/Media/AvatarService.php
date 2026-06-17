<?php
declare(strict_types=1);

namespace App\Media;

use App\Config\Config;
use App\Database\Db;
use App\Support\Clock;

/**
 * M4d: Avatar-Upload, -Storage und -Serving (siehe docs/MILESTONE_4.md
 * §3 D-D1..3).
 *
 * Layout:  <STORAGE_AVATARS_DIR>/<user_id>/avatar.<ext>
 * users.avatar_path ist relativ zur Basis (z. B. "7/avatar.webp").
 *
 * Validierung serverseitig per getimagesize() (kein Vertrauen auf den
 * Content-Type-Header). Erlaubt JPEG/PNG/WebP, max. AVATAR_MAX_BYTES.
 * Bild wird auf max. MAX_DIM Kantenlänge herunterskaliert und als
 * WebP gespeichert (Fallback JPEG, falls WebP-Encoding fehlt). Ohne
 * GD-Extension wird das Original unverändert abgelegt.
 */
final class AvatarService
{
    public const MAX_DIM   = 512;
    public const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    private readonly string $baseDir;

    public function __construct(Config $config)
    {
        $configured = (string)$config->get('STORAGE_AVATARS_DIR', '');
        if ($configured === '') {
            $configured = dirname(__DIR__, 2) . '/storage/avatars';
        }
        $this->baseDir = rtrim($configured, '/');
    }

    public function baseDir(): string
    {
        return $this->baseDir;
    }

    /**
     * Validiert + speichert ein hochgeladenes Avatar-Bild und setzt
     * users.avatar_path. Liefert den neuen relativen Pfad.
     *
     * @param array{tmp_name:string, size:int, type:string, name:string} $upload
     */
    public function store(int $userId, array $upload): string
    {
        $tmp  = $upload['tmp_name'];
        $size = (int)$upload['size'];

        if ($size <= 0 || !is_readable($tmp)) {
            throw new AvatarException('avatar_required', 'Bilddatei ist erforderlich.', 422);
        }
        if ($size > self::MAX_BYTES) {
            throw new AvatarException('avatar_too_large',
                'Bild ist zu groß (max. 5 MB).', 413);
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            throw new AvatarException('avatar_invalid',
                'Datei ist kein gültiges Bild.', 422);
        }
        $imageType = (int)$info[2];
        $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
        if (!in_array($imageType, $allowed, true)) {
            throw new AvatarException('avatar_invalid_type',
                'Nur JPEG, PNG oder WebP erlaubt.', 422);
        }

        $dir = $this->baseDir . '/' . $userId;
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new AvatarException('avatar_store_failed',
                'Avatar konnte nicht gespeichert werden.', 500);
        }

        // Alte Avatare des Users entfernen (Format könnte wechseln).
        foreach (['webp', 'jpg', 'png'] as $oldExt) {
            $old = $dir . '/avatar.' . $oldExt;
            if (is_file($old)) {
                @unlink($old);
            }
        }

        [$relPath, $abs] = $this->processAndWrite($userId, $dir, $tmp, $imageType);

        $now = Clock::nowUtcString();
        Db::pdo()->prepare(
            'UPDATE users SET avatar_path = ?, updated_at = ? WHERE id = ?'
        )->execute([$relPath, $now, $userId]);

        return $relPath;
    }

    /**
     * Entfernt den Avatar des Users (File + DB-Spalte).
     */
    public function delete(int $userId): void
    {
        $stmt = Db::pdo()->prepare('SELECT avatar_path FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $rel = $stmt->fetchColumn();
        if (is_string($rel) && $rel !== '') {
            $abs = $this->baseDir . '/' . ltrim($rel, '/');
            if (is_file($abs) && !str_contains($rel, '..')) {
                @unlink($abs);
            }
        }
        Db::pdo()->prepare(
            'UPDATE users SET avatar_path = NULL, updated_at = ? WHERE id = ?'
        )->execute([Clock::nowUtcString(), $userId]);
    }

    /**
     * Entfernt nur die Dateien des Users (für Account-Löschung). Setzt
     * die DB-Spalte NICHT (das macht deleteAccount selbst).
     */
    public function deleteFilesForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $dir = $this->baseDir . '/' . $userId;
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)@scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $sub = $dir . '/' . $entry;
            if (is_file($sub)) {
                @unlink($sub);
            }
        }
        @rmdir($dir);
    }

    /**
     * Liefert für einen aktiven Handle den absoluten Avatar-Pfad +
     * MIME-Typ, falls ein Avatar gesetzt ist und die Datei existiert.
     *
     * @return array{path:string, mime:string}|null
     */
    public function resolveForHandle(string $handle): ?array
    {
        if (preg_match('/^[a-z0-9_]{3,30}$/', $handle) !== 1) {
            return null;
        }
        $stmt = Db::pdo()->prepare(
            "SELECT avatar_path FROM users
              WHERE public_handle = ? AND status = 'active' LIMIT 1"
        );
        $stmt->execute([$handle]);
        $rel = $stmt->fetchColumn();
        if (!is_string($rel) || $rel === '' || str_contains($rel, '..')) {
            return null;
        }
        $abs = $this->baseDir . '/' . ltrim($rel, '/');
        if (!is_file($abs)) {
            return null;
        }
        return ['path' => $abs, 'mime' => $this->mimeForExt(pathinfo($abs, PATHINFO_EXTENSION))];
    }

    /**
     * Erzeugt ein deterministisches Placeholder-PNG (farbiges Quadrat
     * mit Initiale) für User ohne Avatar. Liefert den PNG-Bytestring.
     */
    public function placeholderPng(string $seed, int $dim = 256): string
    {
        $letter = strtoupper(substr(trim($seed), 0, 1));
        if ($letter === '' || preg_match('/[A-Z0-9]/', $letter) !== 1) {
            $letter = '?';
        }

        if (!\function_exists('imagecreatetruecolor')) {
            // Ohne GD: minimal valides 1x1-PNG (transparent).
            return base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
            );
        }

        // Hintergrundfarbe aus dem Seed-Hash (stabil pro Handle).
        $hash = crc32($seed);
        $r = 80 + ($hash & 0x7F);
        $g = 80 + (($hash >> 8) & 0x7F);
        $b = 80 + (($hash >> 16) & 0x7F);

        $img = imagecreatetruecolor($dim, $dim);
        $bg  = imagecolorallocate($img, $r, $g, $b);
        $fg  = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $dim, $dim, $bg);

        // Eingebaute GD-Schrift skaliert begrenzt — wir zeichnen die
        // Initiale mit der größten Built-in-Font und zentrieren grob.
        $font = 5;
        $cw = imagefontwidth($font);
        $ch = imagefontheight($font);
        $scale = (int)max(1, floor($dim / ($ch * 1.6)));
        // Mehrfach übereinander für „fett/groß" — simpel, aber lesbar.
        $tmpW = $cw; $tmpH = $ch;
        $glyph = imagecreatetruecolor($tmpW, $tmpH);
        $gbg = imagecolorallocate($glyph, $r, $g, $b);
        imagefilledrectangle($glyph, 0, 0, $tmpW, $tmpH, $gbg);
        imagestring($glyph, $font, 0, 0, $letter, $fg);
        $dstW = $tmpW * $scale; $dstH = $tmpH * $scale;
        imagecopyresized($img, $glyph, (int)(($dim - $dstW) / 2), (int)(($dim - $dstH) / 2),
            0, 0, $dstW, $dstH, $tmpW, $tmpH);
        imagedestroy($glyph);

        ob_start();
        imagepng($img);
        $png = (string)ob_get_clean();
        imagedestroy($img);
        return $png;
    }

    /**
     * Skaliert das Bild auf MAX_DIM herunter und schreibt es. Liefert
     * [relPath, absPath].
     *
     * @return array{0:string, 1:string}
     */
    private function processAndWrite(int $userId, string $dir, string $tmp, int $imageType): array
    {
        // Ohne GD: Original im erkannten Format ablegen, kein Resize.
        if (!\function_exists('imagecreatetruecolor')) {
            $ext = match ($imageType) {
                IMAGETYPE_PNG  => 'png',
                IMAGETYPE_WEBP => 'webp',
                default        => 'jpg',
            };
            $rel = $userId . '/avatar.' . $ext;
            $abs = $dir . '/avatar.' . $ext;
            @copy($tmp, $abs);
            @chmod($abs, 0640);
            return [$rel, $abs];
        }

        $src = match ($imageType) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_WEBP => @imagecreatefromwebp($tmp),
            default        => false,
        };
        if ($src === false) {
            throw new AvatarException('avatar_invalid', 'Bild konnte nicht gelesen werden.', 422);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, self::MAX_DIM / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        // Weißer Hintergrund für transparente PNGs (WebP/JPEG ohne Alpha).
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);

        if (\function_exists('imagewebp')) {
            $rel = $userId . '/avatar.webp';
            $abs = $dir . '/avatar.webp';
            imagewebp($dst, $abs, 82);
        } else {
            $rel = $userId . '/avatar.jpg';
            $abs = $dir . '/avatar.jpg';
            imagejpeg($dst, $abs, 85);
        }
        imagedestroy($dst);
        @chmod($abs, 0640);

        return [$rel, $abs];
    }

    private function mimeForExt(string $ext): string
    {
        return match (strtolower($ext)) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
