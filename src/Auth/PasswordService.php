<?php
declare(strict_types=1);

namespace App\Auth;

final class PasswordService
{
    private string $algo;
    private array $opts;

    public function __construct()
    {
        if (defined('PASSWORD_ARGON2ID')) {
            $this->algo = PASSWORD_ARGON2ID;
            $this->opts = [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ];
        } else {
            $this->algo = PASSWORD_BCRYPT;
            $this->opts = ['cost' => 12];
        }
    }

    public function hash(string $password): string
    {
        return password_hash($password, $this->algo, $this->opts);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algo, $this->opts);
    }
}
