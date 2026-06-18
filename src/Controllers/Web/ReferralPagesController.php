<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Config\Config;
use App\Http\Request;
use App\Http\Response;

/**
 * M7: Öffentliche Einlade-Landingpage `GET /i/{code}`.
 *
 * Wird von iOS als Universal Link abgefangen (siehe AASA, Pfad /i/*). Ist
 * die App nicht installiert, landet der Browser hier: wir bewerben die App,
 * zeigen den Code sichtbar (für manuelle Eingabe) und verlinken den
 * App-Store sowie die Web-Registrierung mit vorausgefülltem Code.
 *
 * Kein Login nötig. Wir bestätigen NICHT, ob der Code gültig ist (kein
 * Enumerations-/Privacy-Leak über fremde Werber) — die Seite rendert immer
 * gleich und der Code wird erst beim eigentlichen Signup aufgelöst.
 */
final class ReferralPagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly Config $config,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function landing(Request $req): void
    {
        $code = (string)($req->routeParams['code'] ?? '');
        // Defensive Bereinigung — nur das erlaubte Code-Format durchlassen.
        if (preg_match('/^[a-z0-9_-]{1,16}$/i', $code) !== 1) {
            Response::redirect('/register');
        }
        $code = strtolower($code);

        $this->view->render('referral/landing', [
            '_title'        => 'Einladung zu GravelExplorer',
            'referral_code' => $code,
            'app_store_url' => (string)$this->config->get('APP_STORE_URL', ''),
            'register_url'  => '/register?ref=' . rawurlencode($code),
            'flash'         => null,
        ]);
    }
}
