<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthException;
use App\Auth\AuthService;
use App\Auth\CookieAuth;
use App\Auth\RateLimiter;
use App\Config\Config;
use App\Http\Middleware\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validator;

final class AuthPagesController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CookieAuth $cookieAuth,
        private readonly RateLimiter $rate,
        private readonly string $viewsPath,
    ) {}

    public function showLogin(Request $req): void
    {
        Csrf::ensureStarted();
        $this->render('login', [
            'flash' => $this->popFlash(),
            'email' => '',
            'error' => null,
        ]);
    }

    public function doLogin(Request $req): void
    {
        Csrf::ensureStarted();
        $email = (string)($req->post['email'] ?? '');
        $pw    = (string)($req->post['password'] ?? '');

        $loginMax = Config::instance()->int('RATE_LOGIN_MAX', 10);
        $this->limit('login', $req->ip, $loginMax);
        if ($email !== '') {
            $this->limit('login', strtolower(trim($email)), $loginMax);
        }

        $v = new Validator();
        $cleanEmail = $v->email('email', $email);
        if ($cleanEmail === null || $pw === '') {
            $this->render('login', ['error' => 'Ungültige Anmeldedaten.', 'email' => $email, 'flash' => null], 401);
        }

        try {
            $result = $this->auth->login($cleanEmail, $pw, 'web', $req->userAgent, $req->ipBinary());
        } catch (AuthException $e) {
            $this->render('login', ['error' => $e->getMessage(), 'email' => $email, 'flash' => null], $e->httpStatus);
        }

        // C1/C4: Session-Fixation verhindern und CSRF-Token nach Auth-Wechsel
        // rotieren — bevor wir das Auth-Cookie setzen.
        Csrf::rotateForAuthState();
        $this->cookieAuth->setFromTokens($result['tokens']);
        Response::redirect('/dashboard');
    }

    public function showRegister(Request $req): void
    {
        Csrf::ensureStarted();
        $this->render('register', [
            'errors' => [],
            'email'  => '',
            'display_name' => '',
            'flash' => $this->popFlash(),
        ]);
    }

    public function doRegister(Request $req): void
    {
        Csrf::ensureStarted();
        $this->limit('register', $req->ip, Config::instance()->int('RATE_REGISTER_MAX', 10));

        $emailRaw = (string)($req->post['email'] ?? '');
        $displayName = (string)($req->post['display_name'] ?? '');
        $pw       = (string)($req->post['password'] ?? '');

        $v = new Validator();
        $email = $v->email('email', $emailRaw);
        $password = $v->password('password', $pw, $email);
        $cleanName = $v->displayName('display_name', $displayName);

        if ($v->fails()) {
            $this->render('register', [
                'errors' => $v->errors(),
                'email' => $emailRaw,
                'display_name' => $displayName,
                'flash' => null,
            ], 422);
        }

        try {
            $this->auth->register($email, $password, $cleanName);
        } catch (AuthException $e) {
            $this->render('register', [
                'errors' => $e->fields ?? ['email' => [$e->getMessage()]],
                'email' => $emailRaw,
                'display_name' => $displayName,
                'flash' => null,
            ], $e->httpStatus);
        }

        // C1/C4: Auch nach Register Session-ID + CSRF-Token rotieren, damit
        // ein evtl. parallel laufender Angreifer auf die alte Session-ID
        // nichts mehr ausrichten kann.
        Csrf::rotateForAuthState();
        // C2: Kein Auto-Login mehr — die Antwort darf nicht verraten, ob
        // gerade ein Konto angelegt wurde oder nicht. Generische Flash und
        // Redirect zum Login.
        $this->setFlash('Wenn dein Konto neu ist, haben wir dir eine Bestätigungs-E-Mail geschickt. Bitte melde dich anschließend an.');
        Response::redirect('/login');
    }

    public function showForgot(Request $req): void
    {
        Csrf::ensureStarted();
        $this->render('forgot', ['flash' => $this->popFlash(), 'email' => '']);
    }

    public function doForgot(Request $req): void
    {
        Csrf::ensureStarted();
        $emailRaw = (string)($req->post['email'] ?? '');
        $forgotMax = Config::instance()->int('RATE_FORGOT_MAX', 5);
        $this->limit('forgot-password', $req->ip, $forgotMax);
        if ($emailRaw !== '') {
            $this->limit('forgot-password', strtolower(trim($emailRaw)), $forgotMax);
        }

        $v = new Validator();
        $email = $v->email('email', $emailRaw);
        if ($email !== null) {
            $this->auth->requestPasswordReset($email, $req->ipBinary());
        }
        $this->setFlash('Wenn ein Konto existiert, haben wir dir eine E-Mail gesendet.');
        Response::redirect('/forgot-password');
    }

    public function showReset(Request $req): void
    {
        Csrf::ensureStarted();
        $token = (string)($req->query['token'] ?? '');
        $this->render('reset', [
            'token' => $token,
            'errors' => [],
            'flash' => $this->popFlash(),
        ]);
    }

    public function doReset(Request $req): void
    {
        Csrf::ensureStarted();
        $token = (string)($req->post['token'] ?? '');
        $pw    = (string)($req->post['new_password'] ?? '');

        $v = new Validator();
        $cleanToken = $v->nonEmptyString('token', $token, 512);
        $newPw = $v->password('new_password', $pw);
        if ($v->fails()) {
            $this->render('reset', ['token' => $token, 'errors' => $v->errors(), 'flash' => null], 422);
        }

        try {
            $this->auth->resetPassword($cleanToken, $newPw);
        } catch (AuthException $e) {
            $this->render('reset', ['token' => $token, 'errors' => $e->fields ?? ['token' => [$e->getMessage()]], 'flash' => null], $e->httpStatus);
        }
        // C1/C4: Nach erfolgreichem Reset frische Session + CSRF-Token.
        Csrf::rotateForAuthState();
        $this->setFlash('Passwort aktualisiert. Bitte melde dich neu an.');
        Response::redirect('/login');
    }

    public function showVerify(Request $req): void
    {
        Csrf::ensureStarted();
        $token = (string)($req->query['token'] ?? '');
        $status = null;
        $message = null;
        if ($token === '') {
            $status = 'error';
            $message = 'Kein Token in der URL gefunden.';
        } else {
            try {
                $this->auth->verifyEmail($token);
                $status = 'success';
                $message = 'Deine E-Mail-Adresse wurde erfolgreich bestätigt.';
            } catch (AuthException $e) {
                $status = 'error';
                $message = $e->getMessage();
            }
        }
        $this->render('verify', ['status' => $status, 'message' => $message, 'flash' => null]);
    }

    public function doLogout(Request $req): void
    {
        $ctx = $this->cookieAuth->resolve($req);
        if ($ctx !== null) {
            $this->auth->logout((int)$ctx['session_id']);
        }
        $this->cookieAuth->clear();
        // C1/C4: Beim Logout frische Session-ID + CSRF-Token erzwingen.
        Csrf::rotateForAuthState();
        Response::redirect('/login');
    }

    private function limit(string $action, string $identifier, int $max): void
    {
        if ($this->rate->hit($action, $identifier, $max)) {
            header('Retry-After: ' . $this->rate->retryAfter());
            Response::html('<!doctype html><meta charset="utf-8"><title>429</title><h1>Zu viele Anfragen</h1><p>Bitte versuche es in Kürze erneut.</p>', 429);
        }
    }

    private function render(string $view, array $vars = [], int $status = 200): never
    {
        Csrf::ensureStarted();
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        $vars['_csrf'] = Csrf::token();
        $vars['_title'] = $vars['_title'] ?? ucfirst($view) . ' · GravelExplorer';
        $vars['_view'] = $view;
        extract($vars, EXTR_SKIP);
        $layout = $this->viewsPath . '/web/layout.php';
        $partial = $this->viewsPath . '/web/' . $view . '.php';

        ob_start();
        include $partial;
        $content = (string)ob_get_clean();

        include $layout;
        exit;
    }

    private function setFlash(string $msg): void
    {
        Csrf::ensureStarted();
        $_SESSION['flash'] = $msg;
    }

    private function popFlash(): ?string
    {
        Csrf::ensureStarted();
        if (isset($_SESSION['flash'])) {
            $msg = (string)$_SESSION['flash'];
            unset($_SESSION['flash']);
            return $msg;
        }
        return null;
    }
}
