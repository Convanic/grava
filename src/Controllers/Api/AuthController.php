<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\AuthException;
use App\Auth\AuthService;
use App\Auth\RateLimiter;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validator;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly RateLimiter $rate,
    ) {}

    public function register(Request $req): void
    {
        $this->rateLimit('register', $req->ip, 10);

        $v = new Validator();
        $email = $v->email('email', $req->input('email'));
        $password = $v->password('password', $req->input('password'), $email);
        $displayName = $v->displayName('display_name', $req->input('display_name'));
        if ($v->fails()) {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, $v->errors());
        }

        try {
            $result = $this->auth->register(
                $email, $password, $displayName,
                $this->client($req), $req->userAgent, $req->ipBinary(),
            );
        } catch (AuthException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields);
        }

        Response::json($this->tokenPayload($result), 201);
    }

    public function login(Request $req): void
    {
        $this->rateLimit('login', $req->ip, $this->maxFor('login'));
        $emailRaw = (string)$req->input('email', '');
        if ($emailRaw !== '') {
            $this->rateLimit('login', strtolower(trim($emailRaw)), $this->maxFor('login'));
        }

        $v = new Validator();
        $email = $v->email('email', $emailRaw);
        $password = is_string($req->input('password')) ? (string)$req->input('password') : '';

        if ($email === null || $password === '') {
            Response::error('invalid_credentials', 'Ungültige Anmeldedaten.', 401);
        }

        try {
            $result = $this->auth->login(
                $email, $password, $this->client($req),
                $req->userAgent, $req->ipBinary(),
            );
        } catch (AuthException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields);
        }

        Response::json($this->tokenPayload($result));
    }

    public function refresh(Request $req): void
    {
        $refresh = (string)$req->input('refresh_token', '');
        if ($refresh === '') {
            Response::error('invalid_token', 'Refresh-Token fehlt.', 401);
        }
        try {
            $result = $this->auth->refresh($refresh, $req->userAgent, $req->ipBinary());
        } catch (AuthException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields);
        }
        Response::json($this->tokenPayload($result));
    }

    public function logout(Request $req): void
    {
        $this->auth->logout((int)$req->sessionId);
        Response::noContent();
    }

    public function logoutAll(Request $req): void
    {
        $this->auth->logoutAll((int)($req->user->internal_id ?? 0));
        Response::noContent();
    }

    public function changePassword(Request $req): void
    {
        $v = new Validator();
        $current = is_string($req->input('current_password')) ? (string)$req->input('current_password') : '';
        $email = $req->user->email ?? null;
        $newPw = $v->password('new_password', $req->input('new_password'), $email);
        if ($current === '') {
            $v->add('current_password', 'Aktuelles Passwort ist erforderlich.');
        }
        if ($v->fails()) {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, $v->errors());
        }

        try {
            $this->auth->changePassword(
                (int)($req->user->internal_id ?? 0),
                (int)$req->sessionId,
                $current,
                $newPw,
            );
        } catch (AuthException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields);
        }
        Response::noContent();
    }

    public function forgotPassword(Request $req): void
    {
        $emailRaw = (string)$req->input('email', '');
        $this->rateLimit('forgot-password', $req->ip, $this->maxFor('forgot'));
        if ($emailRaw !== '') {
            $this->rateLimit('forgot-password', strtolower(trim($emailRaw)), $this->maxFor('forgot'));
        }

        $v = new Validator();
        $email = $v->email('email', $emailRaw);
        if ($email !== null) {
            $this->auth->requestPasswordReset($email, $req->ipBinary());
        }
        Response::json(['message' => 'Wenn ein Konto existiert, haben wir eine E-Mail gesendet.'], 202);
    }

    public function resetPassword(Request $req): void
    {
        $v = new Validator();
        $token = $v->nonEmptyString('token', $req->input('token'), 512);
        $newPw = $v->password('new_password', $req->input('new_password'));
        if ($v->fails()) {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, $v->errors());
        }
        try {
            $this->auth->resetPassword($token, $newPw);
        } catch (AuthException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields);
        }
        Response::noContent();
    }

    public function verifyEmail(Request $req): void
    {
        $token = (string)$req->input('token', '');
        if ($token === '') {
            Response::error('validation_error', 'Token fehlt.', 422, ['token' => ['Required.']]);
        }
        try {
            $user = $this->auth->verifyEmail($token);
        } catch (AuthException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields);
        }
        Response::json(['user' => $user]);
    }

    public function resendVerification(Request $req): void
    {
        if ($req->user !== null) {
            $this->rateLimit('verify-resend', (string)($req->user->internal_id ?? 'authed'), $this->maxFor('verify_resend'));
            $this->auth->resendVerificationForUser((int)($req->user->internal_id ?? 0));
        } else {
            $emailRaw = (string)$req->input('email', '');
            $this->rateLimit('verify-resend', $req->ip, $this->maxFor('verify_resend'));
            if ($emailRaw !== '') {
                $this->rateLimit('verify-resend', strtolower(trim($emailRaw)), $this->maxFor('verify_resend'));
                $v = new Validator();
                $email = $v->email('email', $emailRaw);
                if ($email !== null) {
                    $this->auth->resendVerification($email);
                }
            }
        }
        Response::json(['message' => 'Wenn nötig, haben wir eine E-Mail gesendet.'], 202);
    }

    private function tokenPayload(array $result): array
    {
        $t = $result['tokens'];
        return [
            'access_token'        => $t['access_token'],
            'access_expires_in'   => $t['access_expires_in'],
            'refresh_token'       => $t['refresh_token'],
            'refresh_expires_in'  => $t['refresh_expires_in'],
            'token_type'          => 'Bearer',
            'user'                => $result['user'],
        ];
    }

    private function rateLimit(string $action, string $identifier, int $max): void
    {
        if ($this->rate->hit($action, $identifier, $max)) {
            header('Retry-After: ' . $this->rate->retryAfter());
            Response::error('rate_limited', 'Zu viele Anfragen. Bitte später erneut versuchen.', 429);
        }
    }

    private function client(Request $req): string
    {
        $hdr = strtolower(trim($req->header('X-Client', '')));
        return in_array($hdr, ['ios','web','other'], true) ? $hdr : 'other';
    }

    private function maxFor(string $name): int
    {
        $map = [
            'login'         => (int)($_ENV['RATE_LOGIN_MAX']         ?? 10),
            'register'      => (int)($_ENV['RATE_REGISTER_MAX']      ?? 10),
            'forgot'        => (int)($_ENV['RATE_FORGOT_MAX']        ?? 5),
            'verify_resend' => (int)($_ENV['RATE_VERIFY_RESEND_MAX'] ?? 5),
        ];
        return $map[$name] ?? 10;
    }
}
