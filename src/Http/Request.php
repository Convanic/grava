<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string,string> */
    public array $headers;
    /** @var array<string,mixed> */
    public array $json = [];
    /** @var array<string,mixed> */
    public array $post = [];
    /** @var array<string,mixed> */
    public array $query = [];
    /** @var array<string,string> */
    public array $cookies;
    public ?object $user = null;
    public ?int $sessionId = null;
    public ?int $accessTokenId = null;
    /** @var array<string,string> */
    public array $routeParams = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $rawBody,
        public readonly string $ip,
        public readonly string $userAgent,
        ?array $headers = null,
        ?array $cookies = null,
        ?array $query = null,
        ?array $post = null,
    ) {
        $this->headers = $headers ?? [];
        $this->cookies = $cookies ?? [];
        $this->query = $query ?? [];
        $this->post = $post ?? [];

        if ($this->rawBody !== '' && stripos($this->header('Content-Type', ''), 'application/json') !== false) {
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                $this->json = $decoded;
            }
        }
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $headers[$name] = (string)$v;
            } elseif (in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $k))));
                $headers[$name] = (string)$v;
            }
        }

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $raw = file_get_contents('php://input') ?: '';
        $ip = self::clientIp();
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

        return new self(
            method: $method,
            path: $path,
            rawBody: $raw,
            ip: $ip,
            userAgent: $ua,
            headers: $headers,
            cookies: array_map('strval', $_COOKIE),
            query: $_GET,
            post: $_POST,
        );
    }

    public function header(string $name, string $default = ''): string
    {
        foreach ($this->headers as $k => $v) {
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }
        return $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if ($auth === '') return null;
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json[$key] ?? $this->post[$key] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function isJson(): bool
    {
        return stripos($this->header('Content-Type'), 'application/json') !== false
            || stripos($this->header('Accept'), 'application/json') !== false;
    }

    private static function clientIp(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function ipBinary(): ?string
    {
        $packed = @inet_pton($this->ip);
        return $packed !== false ? $packed : null;
    }
}
