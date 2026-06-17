<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;

/**
 * Mittelware, die einen verifizierten E-Mail-Status verlangt
 * (M5 aus dem M1-Code-Review).
 *
 * Vorbedingung: {@see RequireBearer} läuft VORHER. Diese Middleware
 * erwartet ein bereits aufgelöstes `$request->user`-Objekt.
 *
 * Verhalten:
 *  - `email_verified === true` → durchwinken
 *  - sonst → HTTP 403 mit Code `email_verification_required`
 *
 * Wird gezielt nur an einzelnen Endpoints (z. B. POST /routes)
 * gebunden, nicht global. So kann ein User Routen verwalten, die
 * er vor einer evtl. nachträglichen Verify-Pflicht angelegt hat,
 * aber keine neuen anlegen, bis er die Mail bestätigt.
 *
 * Die Web-UI rendert dafür ein eigenes Banner (Phase 6) statt der
 * Upload-Form, sodass der User nicht erst nach dem Submit gegen
 * eine 403 läuft.
 */
final class RequireVerified
{
    public function __invoke(Request $request): void
    {
        $u = $request->user;
        if ($u === null) {
            // RequireBearer hätte vorher 401 antworten müssen — wenn wir
            // hier landen, ist das Wiring kaputt. Sicherheits-Fallback:
            Response::error('unauthorized', 'Authentifizierung erforderlich.', 401);
        }
        $verified = (bool)($u->email_verified ?? false);
        if (!$verified) {
            Response::error(
                'email_verification_required',
                'Bitte bestätige zuerst deine E-Mail-Adresse.',
                403,
            );
        }
    }
}
