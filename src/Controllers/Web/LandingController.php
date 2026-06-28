<?php
namespace App\Controllers\Web;

/**
 * Landing-Page Controller (DEV)
 *
 * Temporärer Controller für die neue Landing-Page während der Entwicklung.
 * Finale Integration: → MarketingController, Route / statt /landing
 */
class LandingController
{
    private readonly WebView $view;

    public function __construct(?string $viewsPath = null)
    {
        $viewsPath = $viewsPath ?? dirname(__DIR__, 3) . '/views';
        $this->view = new WebView($viewsPath);
    }

    public function home(): never
    {
        // Mock-Stats für Layout-Test
        // TODO: Durch echte DB-Abfragen ersetzen (siehe README.md)
        $stats = [
            'surface_percentage' => '42%',     // Prozent Schotter-Anteil
            'signups_today' => 12,             // Anmeldungen heute
            'regions_count' => '3',            // DE, AT, CH
            'km_today' => 340,                 // Kilometer heute
        ];

        $this->view->render('landing/home', [
            '_title' => 'GRAVA — Finde, fahre und erobere Deine Tour',
            '_authedUser' => null, // Anonymer Besucher
            '_pageStyles' => ['/assets/landing/landing.css'],
            '_layoutWide' => true,
            'stats' => $stats,
        ]);
    }
}
