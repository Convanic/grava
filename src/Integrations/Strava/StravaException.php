<?php
declare(strict_types=1);

namespace App\Integrations\Strava;

use RuntimeException;

/**
 * M4e: Exception für Strava-Integration (errorCode + httpStatus).
 */
final class StravaException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
