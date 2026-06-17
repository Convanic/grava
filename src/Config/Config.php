<?php
declare(strict_types=1);

namespace App\Config;

use Dotenv\Dotenv;
use RuntimeException;

final class Config
{
    /** @var array<string,mixed> */
    private array $values = [];
    private static ?Config $instance = null;

    private function __construct(string $basePath)
    {
        if (file_exists($basePath . '/.env')) {
            $dotenv = Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
        }

        foreach ($_ENV as $k => $v) {
            $this->values[$k] = $v;
        }
    }

    public static function boot(string $basePath): self
    {
        if (self::$instance === null) {
            self::$instance = new self($basePath);
        }
        return self::$instance;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Config not booted.');
        }
        return self::$instance;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->get($key, $default);
        return is_numeric($v) ? (int)$v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->get($key, $default);
        if (is_bool($v)) return $v;
        if (is_string($v)) {
            $lc = strtolower(trim($v));
            return in_array($lc, ['1','true','yes','on'], true);
        }
        return (bool)$v;
    }

    /**
     * L9: Methode hieß ursprünglich `require()`, was zwar in PHP 8 kein
     * Hard-Reserved-Keyword ist, aber in jedem Editor-Highlighting für
     * Verwirrung sorgt und bei Static-Analysis-Tools regelmäßig falsche
     * Treffer auslöst. `requireValue()` ist eindeutig.
     */
    public function requireValue(string $key): string
    {
        $v = $this->get($key);
        if ($v === null || $v === '') {
            throw new RuntimeException("Missing required config: {$key}");
        }
        return (string)$v;
    }

    public function isProduction(): bool
    {
        return strtolower((string)$this->get('APP_ENV', 'production')) === 'production';
    }
}
