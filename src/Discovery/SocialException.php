<?php
declare(strict_types=1);

namespace App\Discovery;

use RuntimeException;

/**
 * M3 Phase 4: gemeinsame Exception-Klasse für FollowService /
 * BlockService. Trägt error_code + http_status, sodass Controller
 * sie 1:1 in Response::error übergeben können.
 */
final class SocialException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
