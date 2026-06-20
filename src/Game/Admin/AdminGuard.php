<?php
declare(strict_types=1);
namespace App\Game\Admin;

/** ADMIN_EMAILS-Gate, rein + testbar (keine Response-Seiteneffekte). */
final class AdminGuard
{
    public function __construct(private readonly string $adminEmailsCsv) {}

    public function isAdminEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || trim($this->adminEmailsCsv) === '') {
            return false;
        }
        foreach (explode(',', $this->adminEmailsCsv) as $cand) {
            if (strtolower(trim($cand)) === $email) {
                return true;
            }
        }
        return false;
    }
}
