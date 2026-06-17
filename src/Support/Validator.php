<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Collects per-field validation errors and returns them in the
 * exact shape required by the API error envelope.
 */
final class Validator
{
    private const COMMON_PASSWORDS = [
        'password', 'passwort', '12345678', '123456789', '1234567890',
        'qwertyui', 'qwertz12', 'iloveyou', 'admin1234', 'welcome01',
        'letmein01', 'changeme', 'password1', 'password!',
    ];

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
        if (in_array(strtolower($value), self::COMMON_PASSWORDS, true)) {
            $this->add($field, 'Dieses Passwort ist zu häufig. Bitte wähle ein anderes.');
            return null;
        }
        return $value;
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
