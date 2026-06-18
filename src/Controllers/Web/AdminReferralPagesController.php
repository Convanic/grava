<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Auth\AuthService;
use App\Auth\WebSession;
use App\Config\Config;
use App\Http\Request;
use App\Http\Response;
use App\Referral\ReferralService;

/**
 * M7: Geschützte Admin-Auswertung der Empfehlungen.
 *
 *  - GET /admin/referrals       Werber-Liste + Conversion + Bestenliste,
 *                               Zeitraumfilter (?from=&to=)
 *  - GET /admin/referrals.csv   Gleiche Daten als CSV-Export
 *
 * Schutz: eingeloggte Web-Session UND E-Mail in ADMIN_EMAILS (.env,
 * kommagetrennt). Kein öffentliches Leaderboard. Nicht-Admins erhalten
 * 404 (verschleiert die Existenz der Seite).
 */
final class AdminReferralPagesController
{
    private readonly WebView $view;

    public function __construct(
        private readonly WebSession $webSession,
        private readonly AuthService $auth,
        private readonly ReferralService $referrals,
        private readonly Config $config,
        string $viewsPath,
    ) {
        $this->view = new WebView($viewsPath);
    }

    public function index(Request $req): void
    {
        $admin = $this->requireAdmin();

        [$from, $to] = $this->dateRange($req);
        $rows = $this->referrals->adminReport($from, $to);

        $this->view->render('admin/referrals', [
            '_title'      => 'Empfehlungen · Admin',
            '_authedUser' => $admin,
            '_layoutWide' => true,
            'rows'        => $rows,
            'from'        => $from,
            'to'          => $to,
            'totals'      => $this->totals($rows),
            'flash'       => null,
        ]);
    }

    public function csv(Request $req): void
    {
        $this->requireAdmin();

        [$from, $to] = $this->dateRange($req);
        $rows = $this->referrals->adminReport($from, $to);

        $fh = fopen('php://temp', 'r+');
        // PHP 8.4: escape explizit setzen (Default wird deprecated).
        fputcsv($fh, ['referrer_id', 'handle', 'display_name', 'email', 'registered', 'verified', 'activated', 'conversion', 'first_at', 'last_at'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['referrer_id'],
                $r['handle'] ?? '',
                $r['display_name'] ?? '',
                $r['email'],
                $r['registered'],
                $r['verified'],
                $r['activated'],
                $r['conversion'],
                $r['first_at'],
                $r['last_at'],
            ], ',', '"', '');
        }
        rewind($fh);
        $csv = (string)stream_get_contents($fh);
        fclose($fh);

        header('Content-Disposition: attachment; filename="referrals.csv"');
        Response::text($csv, 200, 'text/csv');
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed> public user des Admins
     */
    private function requireAdmin(): array
    {
        $ctx = $this->webSession->resolve();
        if ($ctx === null) {
            Response::redirect('/login');
        }
        $user = $this->auth->loadUserPublic($ctx['user_id']);
        if (!$this->isAdminEmail((string)($user['email'] ?? ''))) {
            // Verschleiern statt 403 — Existenz der Admin-Seite nicht verraten.
            Response::error('not_found', 'Nicht gefunden.', 404);
        }
        return $user;
    }

    private function isAdminEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }
        $raw = (string)$this->config->get('ADMIN_EMAILS', '');
        if (trim($raw) === '') {
            return false;
        }
        foreach (explode(',', $raw) as $candidate) {
            if (strtolower(trim($candidate)) === $email) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{0:?string,1:?string} [from, to] als Y-m-d oder null
     */
    private function dateRange(Request $req): array
    {
        $norm = static function (mixed $v): ?string {
            if (!is_string($v) || trim($v) === '') {
                return null;
            }
            $v = trim($v);
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        };
        return [$norm($req->query['from'] ?? null), $norm($req->query['to'] ?? null)];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{referrers:int,registered:int,verified:int,activated:int}
     */
    private function totals(array $rows): array
    {
        $t = ['referrers' => count($rows), 'registered' => 0, 'verified' => 0, 'activated' => 0];
        foreach ($rows as $r) {
            $t['registered'] += (int)$r['registered'];
            $t['verified']   += (int)$r['verified'];
            $t['activated']  += (int)$r['activated'];
        }
        return $t;
    }
}
