<?php
declare(strict_types=1);

namespace App\Engagement;

use RuntimeException;

/**
 * M4: gemeinsame Exception-Klasse für den Engagement-Layer (Likes,
 * Comments). Trägt error_code + http_status, sodass Controller sie
 * 1:1 in Response::error übergeben können — analog
 * {@see App\Discovery\SocialException}.
 */
final class EngagementException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
