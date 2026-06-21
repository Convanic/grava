<?php
declare(strict_types=1);

namespace App\Game\Faction;

use RuntimeException;

/**
 * Typisierter Fraktions-Fehler mit API-Fehlercode, HTTP-Status und optionalen
 * Zusatzfeldern (z. B. `retry_at` beim Cooldown). Der Controller mappt ihn auf
 * Response::error($code, $message, $status, $fields).
 */
final class FactionException extends RuntimeException
{
    /** @param array<string,mixed>|null $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        public readonly ?array $fields = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Nicht gefunden.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function validation(string $message): self
    {
        return new self('validation_error', $message, 422);
    }

    public static function forbidden(string $message = 'Nur der Captain darf die Fraktion ändern.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notMember(string $message = 'Du bist in keiner Crew.'): self
    {
        return new self('not_member', $message, 409);
    }

    public static function cooldown(string $retryAtIso): self
    {
        return new self(
            'faction_cooldown',
            'Fraktionswechsel ist noch gesperrt (Cooldown).',
            409,
            ['retry_at' => $retryAtIso],
        );
    }
}
