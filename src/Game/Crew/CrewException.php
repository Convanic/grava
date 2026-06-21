<?php
declare(strict_types=1);

namespace App\Game\Crew;

use RuntimeException;

/**
 * Typisierter Crew-Fehler mit API-Fehlercode + HTTP-Status. Der Controller
 * mappt ihn 1:1 auf Response::error($code, $message, $status).
 */
final class CrewException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Crew nicht gefunden.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function validation(string $message): self
    {
        return new self('validation_error', $message, 422);
    }

    public static function captainMustTransfer(): self
    {
        return new self(
            'captain_must_transfer',
            'Als Captain musst du zuerst die Captain-Rolle übertragen (POST /game/crews/transfer).',
            409,
        );
    }

    public static function full(): self
    {
        return new self('crew_full', 'Diese Crew ist voll.', 409);
    }

    public static function notMember(string $message = 'Du bist in keiner Crew.'): self
    {
        return new self('not_member', $message, 409);
    }

    public static function forbidden(string $message = 'Nicht erlaubt.'): self
    {
        return new self('forbidden', $message, 403);
    }
}
