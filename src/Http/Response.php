<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $k => $v) {
            header("{$k}: {$v}");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $code, string $message, int $status, ?array $fields = null): never
    {
        $body = ['error' => ['code' => $code, 'message' => $message]];
        if ($fields !== null) {
            $body['error']['fields'] = $fields;
        }
        // L13: Server-Fehler (5xx) und Auth/Sec-relevante Antworten ins
        // Errorlog spiegeln — hilft beim Forensik nach einem Vorfall und
        // beim Erkennen von Brute-Force-Wellen über das normale Logfile.
        if ($status >= 500 || in_array($status, [401, 403, 419, 429], true)) {
            error_log(sprintf(
                'Response::error status=%d code=%s message=%s path=%s ip=%s',
                $status,
                $code,
                $message,
                (string)($_SERVER['REQUEST_URI'] ?? '-'),
                (string)($_SERVER['REMOTE_ADDR'] ?? '-'),
            ));
        }
        self::json($body, $status);
    }

    public static function noContent(int $status = 204): never
    {
        http_response_code($status);
        exit;
    }

    public static function redirect(string $location, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $location);
        exit;
    }

    public static function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo $html;
        exit;
    }

    public static function text(string $body, int $status = 200, string $contentType = 'text/plain'): never
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        echo $body;
        exit;
    }
}
