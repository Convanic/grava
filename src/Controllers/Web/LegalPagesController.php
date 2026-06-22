<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Http\Request;

/**
 * Öffentliche Rechtsseiten (siehe backend/LEGAL_PAGES.md): Datenschutz &
 * Nutzungsbedingungen. Anonym erreichbar, kein Login, kein Redirect — die
 * iOS-App und App Store Connect verlinken direkt auf /privacy bzw. /terms.
 *
 * Der eigentliche Rechtstext kommt vom Betreiber; hier liegt nur die
 * Seitenstruktur mit Platzhaltern im bestehenden Web-Layout.
 */
final class LegalPagesController
{
    private readonly WebView $view;

    public function __construct(string $viewsPath)
    {
        $this->view = new WebView($viewsPath);
    }

    public function privacy(Request $req): void
    {
        $this->view->render('legal/privacy', [
            '_title' => 'Datenschutzerklärung · GRAVA',
            'flash'  => null,
        ]);
    }

    public function terms(Request $req): void
    {
        $this->view->render('legal/terms', [
            '_title' => 'Nutzungsbedingungen · GRAVA',
            'flash'  => null,
        ]);
    }
}
