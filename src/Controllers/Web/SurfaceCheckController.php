<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Config\Config;
use App\Heatmap\RouteSurfaceService;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Routes\GeometryParseException;
use Throwable;

/**
 * M9: Surface-Check — Web-Tool, das eine hochgeladene Fremd-Route (z. B.
 * Strava-GPX) gegen die vorhandenen Crowd-Belagsdaten ({@see RouteSurfaceService})
 * abgleicht und ein Belags-Profil + farbige Karte zeigt.
 *
 * Ephemer: die Route wird NICHT persistiert. Das Analyse-Ergebnis (plus die
 * ausgedünnten Punkte für den optionalen Valhalla-"Details"-Pfad) liegt nur
 * kurzzeitig in der Session unter einem Token (PRG-Muster: POST -> Redirect
 * auf GET ?r=token, damit ein Reload nicht erneut hochlädt).
 *
 * Auth-Modell wie {@see RoutePagesController}: WebSession + CSRF.
 */
final class SurfaceCheckController
{
    private const SESSION_KEY = 'surface_check';
    private const TTL_SECONDS  = 900; // 15 min

    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly RouteSurfaceService $surface,
        private readonly Config $config,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    // ---------------------------------------------------------------------
    // GET /surface-check — Formular (oder Ergebnis bei ?r=token)
    // ---------------------------------------------------------------------
    public function showForm(Request $req): void
    {
        $this->guardEnabled();
        [$user] = $this->resolveOrRefresh($req, '/surface-check');

        $token  = (string)($req->query['r'] ?? '');
        $stored = $token !== '' ? $this->loadFromSession($token) : null;

        $this->render('surface-check', $user, [
            '_title'          => 'Belag prüfen · GRAVA',
            'verified'        => (bool)$user['email_verified'],
            'errors'          => [],
            'token'           => $stored !== null ? $token : null,
            'result'          => $stored['result'] ?? null,
            'filename'        => $stored['name'] ?? null,
            'valhallaEnabled' => $this->valhallaEnabled(),
            'flash'           => null,
        ]);
    }

    // ---------------------------------------------------------------------
    // POST /surface-check — Upload + Analyse (Weg C), dann PRG-Redirect
    // ---------------------------------------------------------------------
    public function analyze(Request $req): void
    {
        $this->guardEnabled();
        [$user] = $this->resolveOrRefresh($req, '/surface-check');

        if (!$user['email_verified']) {
            $this->renderError($user, ['payload' => ['Bitte bestätige zuerst deine E-Mail-Adresse.']], 403);
        }

        $upload = $req->file('payload');
        if ($upload === null) {
            $this->renderError($user, ['payload' => ['Bitte wähle eine GPX- oder GeoJSON-Datei.']], 422);
        }
        $maxBytes = $this->config->int('REQUEST_MAX_UPLOAD_BYTES', 26_214_400);
        if ($upload['size'] > $maxBytes) {
            $this->renderError($user, ['payload' => ['Datei ist zu groß.']], 422);
        }

        $payload = @file_get_contents($upload['tmp_name']);
        if ($payload === false || $payload === '') {
            $this->renderError($user, ['payload' => ['Datei konnte nicht gelesen werden.']], 500);
        }

        try {
            $points = $this->surface->routePoints($payload);
            if (count($points) < 2) {
                $this->renderError($user, ['payload' => ['Die Datei enthält keine erkennbare Strecke.']], 422);
            }
            $result    = $this->surface->analyzeSpatialPoints($points);
            $resampled = $this->surface->downsample($points);
        } catch (GeometryParseException $e) {
            $this->renderError($user, ['payload' => [$e->getMessage()]], 422);
        } catch (Throwable $e) {
            $this->renderError($user, ['payload' => ['Analyse fehlgeschlagen: ' . $e->getMessage()]], 500);
        }

        $token = bin2hex(random_bytes(8));
        $this->storeInSession($token, [
            'result' => $result,
            'points' => $resampled,
            'name'   => $this->safeName((string)$upload['name']),
            'ts'     => time(),
        ]);

        Response::redirect('/surface-check?r=' . $token);
    }

    // ---------------------------------------------------------------------
    // GET /surface-check/details — präziser Valhalla-Pfad (JSON, on-demand)
    // ---------------------------------------------------------------------
    public function details(Request $req): void
    {
        $this->guardEnabled();
        // Same-origin-Fetch — sauberes JSON statt HTML-Redirect bei Ablauf.
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            $this->jsonOut(['error' => 'unauthorized'], 401);
        }
        Csrf::ensureStarted();

        if (!$this->valhallaEnabled()) {
            $this->jsonOut(['available' => false, 'reason' => 'disabled']);
        }

        $token  = (string)($req->query['r'] ?? '');
        $stored = $token !== '' ? $this->loadFromSession($token) : null;
        if ($stored === null || !isset($stored['points']) || !is_array($stored['points'])) {
            $this->jsonOut(['available' => false, 'reason' => 'expired']);
        }

        try {
            $result = $this->surface->analyzeValhalla($stored['points']);
        } catch (Throwable) {
            $result = null;
        }
        if ($result === null) {
            $this->jsonOut(['available' => false, 'reason' => 'no_match']);
        }

        $this->jsonOut(['available' => true] + $result);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function valhallaEnabled(): bool
    {
        return $this->config->bool('SURFACE_CHECK_VALHALLA_ENABLED', false);
    }

    /** Deaktiviert das Feature komplett (404), wenn SURFACE_CHECK_ENABLED=false. */
    private function guardEnabled(): void
    {
        if (!$this->config->bool('SURFACE_CHECK_ENABLED', true)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not Found';
            exit;
        }
    }

    /** @return array{0: array<string,mixed>} */
    private function resolveOrRefresh(Request $req, string $next): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/auth/web-refresh?next=' . rawurlencode($next));
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        $user['internal_id'] = $ctx['user_id'];
        Csrf::ensureStarted();
        return [$user];
    }

    /**
     * @param array<string,string[]> $errors
     */
    private function renderError(array $user, array $errors, int $status): never
    {
        $this->render('surface-check', $user, [
            '_title'          => 'Belag prüfen · GRAVA',
            'verified'        => (bool)($user['email_verified'] ?? false),
            'errors'          => $errors,
            'token'           => null,
            'result'          => null,
            'filename'        => null,
            'valhallaEnabled' => $this->valhallaEnabled(),
            'flash'           => null,
        ], $status);
    }

    /** @param array<string,mixed> $vars */
    private function render(string $view, array $user, array $vars, int $status = 200): never
    {
        $vars['_authedUser'] = $user;
        $vars['_layoutWide'] = true;
        $this->view->render($view, $vars, $status);
    }

    /** @param array<string,mixed> $data */
    private function storeInSession(string $token, array $data): void
    {
        Csrf::ensureStarted();
        // Nur das jeweils letzte Ergebnis halten — Session schlank lassen.
        $_SESSION[self::SESSION_KEY] = [$token => $data];
    }

    /** @return array<string,mixed>|null */
    private function loadFromSession(string $token): ?array
    {
        Csrf::ensureStarted();
        $bucket = $_SESSION[self::SESSION_KEY][$token] ?? null;
        if (!is_array($bucket)) {
            return null;
        }
        if ((int)($bucket['ts'] ?? 0) < time() - self::TTL_SECONDS) {
            unset($_SESSION[self::SESSION_KEY][$token]);
            return null;
        }
        return $bucket;
    }

    private function safeName(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\w.\- ]+/u', '', $name) ?? '';
        return mb_substr(trim($name), 0, 120);
    }

    /** @param array<string,mixed> $data */
    private function jsonOut(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
