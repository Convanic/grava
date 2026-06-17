<?php
declare(strict_types=1);

namespace App\Media;

use RuntimeException;

/**
 * M4d: Exception für Avatar-Operationen (errorCode + httpStatus),
 * damit Controller sie 1:1 in Response::error übergeben können.
 */
final class AvatarException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
