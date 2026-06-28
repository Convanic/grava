# Landing Page — Lokaler Testserver

**Zweck:** Anleitung zum Starten des lokalen Entwicklungsservers für die Landing Page unter `/landing`.

## §1 Voraussetzungen

- PHP 8.0+ installiert (`php -v` zum Testen)
- Das Projekt liegt unter `/Users/arminlorenz/Sites/gravelexplorer/`
- Port 8890 muss frei sein

## §2 Server starten

### Option A: PHP Built-in Server (empfohlen für Entwicklung)

```bash
cd /Users/arminlorenz/Sites/gravelexplorer/public
php -S localhost:8890
```

**Erreichbarkeit:**
- Landing Page: http://localhost:8890/landing
- API-Endpunkte: http://localhost:8890/api/v1/*
- Dashboard: http://localhost:8890/dashboard

Der Server läuft im Vordergrund und zeigt alle Requests im Terminal an.

**Stoppen:** `Ctrl+C` im Terminal

### Option B: Im Hintergrund starten

```bash
cd /Users/arminlorenz/Sites/gravelexplorer/public
php -S localhost:8890 &
```

**Stoppen:**
```bash
# Prozess-ID finden
lsof -i :8890 | grep php

# Prozess stoppen (ersetze PID mit der gefundenen Prozess-ID)
kill PID
```

### Option C: Apache (falls bereits konfiguriert)

Falls Apache bereits auf Port 8890 konfiguriert ist:

```bash
# Apache starten
sudo apachectl start

# Apache stoppen
sudo apachectl stop

# Apache neu starten
sudo apachectl restart
```

**Erreichbarkeit mit VirtualHost:**
- http://gravelexplorer.test:8890/landing

## §3 Troubleshooting

### Port bereits belegt

**Problem:** `Failed to listen on localhost:8890 (reason: Address already in use)`

**Lösung:**
```bash
# Prüfen, welcher Prozess Port 8890 nutzt
lsof -i :8890

# Prozess stoppen (httpd oder php)
kill PID
```

### „gravelexplorer.test" nicht erreichbar

**Problem:** Apache ist auf einen Virtual Host `gravelexplorer.test` konfiguriert, aber der Hostname wird nicht aufgelöst.

**Lösung:**
1. Prüfen ob `/etc/hosts` den Eintrag enthält:
   ```bash
   cat /etc/hosts | grep gravelexplorer
   ```

2. Falls nicht vorhanden, Eintrag hinzufügen:
   ```bash
   sudo nano /etc/hosts
   # Zeile hinzufügen:
   127.0.0.1 gravelexplorer.test
   ```

**Alternative:** PHP Built-in Server verwenden (Option A) — funktioniert immer mit `localhost`.

## §4 Landing Page anpassen

Die Landing-Page-Route ist in `public/index.php` Zeile 645 definiert:

```php
$router->get('/landing', fn($r) => $webLanding->home());
```

Der Controller liegt in `src/Controllers/Web/LandingController.php`.
Die View-Templates liegen im Verzeichnis `views/`.

Nach Änderungen an PHP-Dateien: Server neu starten.
Statische Assets (CSS/JS/Bilder) unter `public/assets/` werden direkt ausgeliefert.

## §5 Browser öffnen

**macOS:**
```bash
open http://localhost:8890/landing
```

**Linux:**
```bash
xdg-open http://localhost:8890/landing
```

**Windows:**
```bash
start http://localhost:8890/landing
```
