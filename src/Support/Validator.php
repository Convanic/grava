<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Collects per-field validation errors and returns them in the
 * exact shape required by the API error envelope.
 */
final class Validator
{
    /**
     * Notfall-Fallback, falls die externe Liste in `data/common-passwords.txt`
     * nicht geladen werden kann (z. B. Datei gelöscht, Permission). So bleibt
     * die Mindest-Sperrhürde auch im Defekt-Fall erhalten.
     */
    private const COMMON_PASSWORDS_FALLBACK = [
        'password', 'passwort', '12345678', '123456789', '1234567890',
        'qwertyui', 'qwertz12', 'iloveyou', 'admin1234', 'welcome01',
        'letmein01', 'changeme', 'password1', 'password!',
    ];

    /**
     * M7: Lazy-geladene Top-1000-Liste aus `data/common-passwords.txt`.
     * Wird beim ersten Aufruf in einen Hash-Set (Key = lowercased Passwort,
     * Value = true) eingelesen. Lookup dann O(1).
     *
     * @var array<string,true>|null
     */
    private static ?array $commonPasswordSet = null;

    /** @var array<string,string[]> */
    private array $errors = [];

    public function email(string $field, mixed $value, int $max = 254): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->add($field, 'E-Mail-Adresse ist erforderlich.');
            return null;
        }
        $email = strtolower(trim($value));
        if (strlen($email) > $max) {
            $this->add($field, 'E-Mail-Adresse ist zu lang.');
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->add($field, 'Bitte gib eine gültige E-Mail-Adresse an.');
            return null;
        }
        return $email;
    }

    public function password(string $field, mixed $value, ?string $notEqualToEmail = null): ?string
    {
        if (!is_string($value) || $value === '') {
            $this->add($field, 'Passwort ist erforderlich.');
            return null;
        }
        // M6: Zeichen-, nicht Byte-Längen prüfen. Vorher entstand eine
        // Lüge in der Fehlermeldung („mindestens 10 Zeichen"), wenn der
        // Nutzer Multi-Byte-Zeichen (Umlaute, …) eingab.
        $len = mb_strlen($value, 'UTF-8');
        if ($len < 10) {
            $this->add($field, 'Passwort muss mindestens 10 Zeichen lang sein.');
            return null;
        }
        if ($len > 200) {
            $this->add($field, 'Passwort ist zu lang (max. 200 Zeichen).');
            return null;
        }
        // Argon2id verarbeitet bis 4096 Bytes problemlos, aber zur Sicherheit
        // gegen DoS-Angriffe mit absurd langen Multi-Byte-Strings deckeln
        // wir auch den Byte-Wert (200 Zeichen × 4 Byte/Zeichen UTF-8-max).
        if (strlen($value) > 800) {
            $this->add($field, 'Passwort ist zu lang.');
            return null;
        }
        if ($notEqualToEmail !== null && strcasecmp($value, $notEqualToEmail) === 0) {
            $this->add($field, 'Passwort darf nicht der E-Mail-Adresse entsprechen.');
            return null;
        }
        if (isset(self::commonPasswordSet()[strtolower($value)])) {
            $this->add($field, 'Dieses Passwort ist zu häufig. Bitte wähle ein anderes.');
            return null;
        }
        return $value;
    }

    /**
     * @return array<string,true>
     */
    private static function commonPasswordSet(): array
    {
        if (self::$commonPasswordSet !== null) {
            return self::$commonPasswordSet;
        }
        $path = self::commonPasswordsPath();
        $set  = [];
        if (is_file($path) && is_readable($path)) {
            $fh = fopen($path, 'rb');
            if ($fh !== false) {
                while (($line = fgets($fh)) !== false) {
                    $pw = strtolower(trim($line));
                    if ($pw === '' || str_starts_with($pw, '#')) {
                        continue;
                    }
                    $set[$pw] = true;
                }
                fclose($fh);
            }
        }
        if ($set === []) {
            error_log('Validator::commonPasswordSet: could not load common-passwords.txt, falling back to embedded list.');
            foreach (self::COMMON_PASSWORDS_FALLBACK as $pw) {
                $set[strtolower($pw)] = true;
            }
        }
        self::$commonPasswordSet = $set;
        return self::$commonPasswordSet;
    }

    private static function commonPasswordsPath(): string
    {
        // Liste lebt in <repo-root>/data/common-passwords.txt. Wir liegen
        // unter src/Support, also zwei Ebenen hoch.
        return dirname(__DIR__, 2) . '/data/common-passwords.txt';
    }

    public function displayName(string $field, mixed $value, int $max = 60): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $this->add($field, 'Anzeigename ist ungültig.');
            return null;
        }
        $v = trim($value);
        if (mb_strlen($v) > $max) {
            $this->add($field, 'Anzeigename ist zu lang (max. ' . $max . ' Zeichen).');
            return null;
        }
        // H2/L5: Steuerzeichen (insb. CR/LF) verhindern Mail-Header-Injection
        // im EML-Fallback und Probleme im Front-End-Rendering.
        if (preg_match('/[\x00-\x1F\x7F]/u', $v) === 1) {
            $this->add($field, 'Anzeigename enthält ungültige Steuerzeichen.');
            return null;
        }
        return $v === '' ? null : $v;
    }

    public function nonEmptyString(string $field, mixed $value, int $maxLen = 4096): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->add($field, 'Pflichtfeld.');
            return null;
        }
        if (mb_strlen($value) > $maxLen) {
            $this->add($field, 'Wert ist zu lang.');
            return null;
        }
        return $value;
    }

    /**
     * Optionaler String mit Längen-Cap. Liefert null, wenn nichts
     * angegeben — sonst getrimmten Wert.
     */
    public function optionalString(string $field, mixed $value, int $maxLen = 8000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $this->add($field, 'Wert muss ein String sein.');
            return null;
        }
        $v = trim($value);
        if ($v === '') {
            return null;
        }
        if (mb_strlen($v) > $maxLen) {
            $this->add($field, 'Wert ist zu lang.');
            return null;
        }
        return $v;
    }

    /**
     * Routen-Titel: 1..140 Zeichen, ohne Steuerzeichen.
     */
    public function routeTitle(string $field, mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            $this->add($field, 'Titel ist erforderlich.');
            return null;
        }
        $v = trim($value);
        if (mb_strlen($v) > 140) {
            $this->add($field, 'Titel ist zu lang (max. 140 Zeichen).');
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $v) === 1) {
            $this->add($field, 'Titel enthält ungültige Steuerzeichen.');
            return null;
        }
        return $v;
    }

    /**
     * Sichtbarkeit: 'private' | 'unlisted' | 'public'. Default 'private'.
     */
    public function visibility(string $field, mixed $value, string $default = 'private'): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value)) {
            $this->add($field, 'Sichtbarkeit ist ungültig.');
            return $default;
        }
        $v = strtolower(trim($value));
        if (!in_array($v, ['private', 'unlisted', 'public'], true)) {
            $this->add($field, 'Sichtbarkeit muss private, unlisted oder public sein.');
            return $default;
        }
        return $v;
    }

    /**
     * Quelle: 'app' | 'import' | 'strava' | 'manual'. Default 'app'.
     */
    public function routeSource(string $field, mixed $value, string $default = 'app'): string
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value)) {
            $this->add($field, 'Quelle ist ungültig.');
            return $default;
        }
        $v = strtolower(trim($value));
        if (!in_array($v, ['app', 'import', 'strava', 'manual'], true)) {
            $this->add($field, 'Quelle muss app, import, strava oder manual sein.');
            return $default;
        }
        return $v;
    }

    /**
     * Optionaler UUID v4 in Standard-Schreibweise (lowercase).
     */
    public function uuidOptional(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            $this->add($field, 'UUID ist ungültig.');
            return null;
        }
        $v = strtolower(trim($value));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v)) {
            $this->add($field, 'UUID muss im Format xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx vorliegen.');
            return null;
        }
        return $v;
    }

    /**
     * Tag-Liste: nimmt Array oder Komma-separierten String entgegen.
     * Lowercased, getrimmt, max 40 Zeichen pro Tag, max 32 Tags. Leere
     * Werte werden ignoriert. Reservierte Steuerzeichen werden geprüft.
     *
     * @return list<string>
     */
    public function tags(string $field, mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        }
        if (!is_array($value)) {
            $this->add($field, 'Tags müssen eine Liste sein.');
            return [];
        }
        $out = [];
        foreach ($value as $tag) {
            if (!is_string($tag)) { continue; }
            $clean = strtolower(trim($tag));
            if ($clean === '') { continue; }
            if (mb_strlen($clean) > 40) {
                $this->add($field, 'Tags dürfen maximal 40 Zeichen lang sein.');
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F]/u', $clean) === 1) {
                $this->add($field, 'Tag enthält ungültige Steuerzeichen.');
                continue;
            }
            $out[$clean] = true;
            if (count($out) >= 32) {
                $this->add($field, 'Maximal 32 Tags pro Route.');
                break;
            }
        }
        return array_keys($out);
    }

    public function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /** @return array<string,string[]> */
    public function errors(): array
    {
        return $this->errors;
    }
}
