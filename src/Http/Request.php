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
    /** @var array<string,array<string,mixed>>  Form-name → $_FILES-entry */
    public array $files = [];
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
        ?array $files = null,
    ) {
        $this->headers = $headers ?? [];
        $this->cookies = $cookies ?? [];
        $this->query = $query ?? [];
        $this->post = $post ?? [];
        $this->files = $files ?? [];

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

        // M12 + M2-Phase 4: Body-Cap mit Pfad-bewusstem Override für
        // Routen-Uploads. /api/v1/routes nimmt GPX/GeoJSON-Files entgegen
        // — die sind typischerweise 100 KB bis 5 MB, einzelne Tagestouren
        // können auch mal 15 MB werden. Default-Cap (1 MiB) bleibt für
        // alle anderen Endpoints aktiv, damit eine JSON-DoS-Welle gegen
        // /auth/login keinen 25-MB-Heap pro Request füllen kann.
        $cfg          = \App\Config\Config::instance();
        $apiBase      = rtrim((string)$cfg->get('API_BASE_PATH', '/api/v1'), '/');
        $routesPrefix = $apiBase . '/routes';
        $isUploadPath = $method === 'POST'
            && (str_starts_with($path, $routesPrefix . '/') || $path === $routesPrefix);
        $maxBytes = $isUploadPath
            ? $cfg->int('REQUEST_MAX_UPLOAD_BYTES', 26_214_400)  // 25 MB
            : $cfg->int('REQUEST_MAX_BODY_BYTES',   1_048_576);  // 1 MiB
        $declared = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($declared > $maxBytes) {
            http_response_code(413);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => ['code' => 'payload_too_large', 'message' => 'Anfrage-Body ist zu groß.']]);
            exit;
        }
        $stream = fopen('php://input', 'rb');
        $raw = '';
        if ($stream !== false) {
            $raw = (string)stream_get_contents($stream, $maxBytes + 1);
            fclose($stream);
            if (strlen($raw) > $maxBytes) {
                http_response_code(413);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => ['code' => 'payload_too_large', 'message' => 'Anfrage-Body ist zu groß.']]);
                exit;
            }
        }
        $ip = \App\Support\Ip::clientFromGlobals();
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
            files: $_FILES,
        );
    }

    /**
     * Liefert den $_FILES-Eintrag für ein Form-Feld, oder null wenn
     * der Upload fehlt oder fehlgeschlagen ist.
     *
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}|null
     */
    public function file(string $name): ?array
    {
        $f = $this->files[$name] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return null;
        }
        if (!isset($f['tmp_name']) || !is_string($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) {
            return null;
        }
        return [
            'name'     => (string)($f['name']     ?? ''),
            'type'     => (string)($f['type']     ?? ''),
            'tmp_name' => (string)$f['tmp_name'],
            'error'    => (int)($f['error']    ?? 0),
            'size'     => (int)($f['size']     ?? 0),
        ];
    }

    public function isMultipart(): bool
    {
        return stripos($this->header('Content-Type'), 'multipart/form-data') !== false;
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

    public function ipBinary(): ?string
    {
        $packed = @inet_pton($this->ip);
        return $packed !== false ? $packed : null;
    }
}
