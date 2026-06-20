<?php
declare(strict_types=1);
namespace App\Game\Admin;

/** Entscheidet, ob ein Request-Host der Admin-Host ist. Rein + testbar. */
final class AdminHost
{
    public static function isAdmin(string $requestHost, string $configuredAdminHost, string $appUrl): bool
    {
        $host = strtolower(trim(explode(':', $requestHost)[0] ?? ''));
        $admin = strtolower(trim($configuredAdminHost));
        if ($admin === '') {
            $base = strtolower((string) (parse_url($appUrl, PHP_URL_HOST) ?: ''));
            $admin = $base !== '' ? 'admin.' . ltrim($base, '.') : '';
        } else {
            $admin = trim(explode(':', $admin)[0] ?? '');
        }
        return $admin !== '' && $host === $admin;
    }
}
