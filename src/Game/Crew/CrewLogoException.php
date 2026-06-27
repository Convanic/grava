<?php
declare(strict_types=1);

namespace App\Game\Crew;

use RuntimeException;

/**
 * Typisierter Fehler für Crew-Logo-Operationen (errorCode + httpStatus),
 * damit der Controller sie 1:1 an Response::error übergeben kann. Eigene
 * Klasse statt CrewException, weil das Logo HTTP-Status braucht, die
 * CrewException nicht abdeckt (415 Unsupported Media Type, 413 Payload
 * Too Large).
 */
final class CrewLogoException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $message = 'Crew nicht gefunden.'): self
    {
        return new self('not_found', $message, 404);
    }

    public static function forbidden(string $message = 'Nur der Captain darf das Logo ändern.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function required(): self
    {
        return new self('logo_required', 'Bilddatei ist erforderlich (Form-Feld "logo").', 422);
    }

    public static function tooLarge(): self
    {
        return new self('logo_too_large', 'Bild ist zu groß (max. 8 MB).', 413);
    }

    public static function unsupportedType(): self
    {
        return new self('logo_unsupported_type', 'Nur JPEG oder PNG erlaubt.', 415);
    }

    public static function storeFailed(): self
    {
        return new self('logo_store_failed', 'Logo konnte nicht gespeichert werden.', 500);
    }
}
