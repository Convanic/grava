<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Auth\AuthException;
use App\Auth\AuthService;
use App\Http\Request;
use App\Http\Response;
use App\Support\Validator;

final class UserController
{
    public function __construct(private readonly AuthService $auth) {}

    public function me(Request $req): void
    {
        $user = $this->auth->loadUserPublic((int)($req->user->internal_id ?? 0));
        Response::json(['user' => $user]);
    }

    public function updateMe(Request $req): void
    {
        $v = new Validator();
        $displayName = $v->displayName('display_name', $req->input('display_name'));
        if ($v->fails()) {
            Response::error('validation_error', 'Bitte überprüfe deine Eingaben.', 422, $v->errors());
        }
        $user = $this->auth->updateProfile((int)($req->user->internal_id ?? 0), $displayName);
        Response::json(['user' => $user]);
    }

    public function deleteMe(Request $req): void
    {
        $password = is_string($req->input('password')) ? (string)$req->input('password') : '';
        if ($password === '') {
            Response::error('validation_error', 'Passwort erforderlich.', 422, ['password' => ['Required.']]);
        }
        try {
            $this->auth->deleteAccount((int)($req->user->internal_id ?? 0), $password);
        } catch (AuthException $e) {
            Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields);
        }
        Response::noContent();
    }
}
