<?php
declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

final class AuthException extends RuntimeException
{
    /**
     * @param array<string,string[]>|null $fields
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly ?array $fields = null,
    ) {
        parent::__construct($message);
    }
}
