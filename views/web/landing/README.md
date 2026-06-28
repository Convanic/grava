# GRAVA Landing Page — Entwicklungs-Workspace

Dieser Ordner enthält die neue, publikumstaugliche Landing-Page für den Launch.

## Status

**In Entwicklung** — Layout-Iteration, noch nicht produktiv.

## Dateien

```
landing/
  home.php              Haupt-Landing-Page (Hero + Stats + Features + CTA)
  README.md             Diese Datei

/public/assets/landing/
  landing.css           Styles (nutzt Designsystem "Trail" aus style.css)
  hero-placeholder.jpg  Hero-Bild (Platzhalter)
```

## Lokaler Test

### 1. Controller erstellen (temporär für Entwicklung)

Erstelle `src/Controllers/Web/LandingController.php`:

```php
<?php
namespace App\Controllers\Web;

use App\Http\Response;
use App\Database\Db;

class LandingController
{
    public function home(): Response
    {
        // Mock-Stats für Layout-Test (später durch echte DB-Abfragen ersetzen)
        $stats = [
            'total_km_conquered' => 12450,
            'active_riders' => 847,
            'active_crews' => 214,
            'total_routes' => 1840,
        ];

        ob_start();
        extract(['stats' => $stats]);
        include __DIR__ . '/../../../views/web/landing/home.php';
        $content = ob_get_clean();

        ob_start();
        extract([
            'content' => $content,
            '_title' => 'GRAVA — Entdecke und erobere Radstrecken',
            '_authedUser' => null,
            '_csrf' => '',
            '_pageStyles' => ['/assets/landing/landing.css'],
            '_layoutWide' => true,
        ]);
        include __DIR__ . '/../../../views/web/layout.php';
        $html = ob_get_clean();

        return new Response($html, 200);
    }
}
```

### 2. Route registrieren (temporär)

In `public/index.php` vor den bestehenden Routen:

```php
// DEV: Landing-Page Preview
$router->get('/landing', [new \App\Controllers\Web\LandingController(), 'home']);
```

### 3. Testen

1. MAMP starten
2. Browser: `http://gravelexplorer.test:8890/landing`
3. Layout anpassen in `home.php` + `landing.css`
4. Refresh im Browser

## Design-Referenz

- **Beispiel:** `docs/ex1.png` (Architektur-Portfolio mit Stats-Grid)
- **Designsystem:** `docs/superpowers/specs/2026-06-19-grava-ci-design.md`
- **Tokens:** Alle aus `public/assets/style.css` `:root`

## Nächste Schritte

- [ ] Hero-Bild erstellen (Screenshot aus iOS-App)
- [ ] Stats mit echten DB-Queries verbinden
- [ ] Responsive-Test (Mobile, Tablet, Desktop)
- [ ] Feature-Icons durch echte Icons/SVGs ersetzen
- [ ] App-Store-Badge einbinden
- [ ] Meta-Tags für SEO/Sharing

## Finale Integration

Wenn Layout fertig:
1. Controller nach `src/Controllers/Web/MarketingController.php` verschieben
2. Route `/landing` → `/` umstellen
3. Echte Stats-Queries implementieren (mit Caching)
4. Hero-Bild + Assets finalisieren
