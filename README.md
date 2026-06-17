# GravelExplorer Backend

PHP/MySQL-Backend für die GravelExplorer iOS-App. **Milestone 1** (Auth & Accounts) gemäß
`SPEC.md` aus dem App-Repo.

## Stack

- PHP 8.2+ (entwickelt mit 8.4)
- MySQL 8.0+ (utf8mb4)
- Composer-Abhängigkeiten: PHPMailer, vlucas/phpdotenv, ramsey/uuid

## Verzeichnisstruktur

```
backend/
  public/            DocumentRoot (alle Web-Requests laufen über public/index.php)
    index.php        Front-Controller + CLI-Entry
    .htaccess        Rewrite + Security-Header
    assets/style.css CSS für Web-Seiten
  src/
    Config/          Config-Loader (.env)
    Database/        PDO-Factory + Migrator
    Auth/            AuthService, TokenService, PasswordService, RateLimiter, CookieAuth
    Mail/            MailService (PHPMailer + Datei-Fallback)
    Http/            Router, Request, Response, Middleware (RequireBearer, Csrf)
    Controllers/     Api/* + Web/*
    Support/         Validator, Clock, Uuid
    Cli/             CLI-Befehle
  migrations/        0001_init.sql (+ später weitere)
  views/
    web/             login.php, register.php, ..., layout.php, dashboard.php
    email/           verify_email.{html,txt}.php, reset_password.{html,txt}.php
  storage/
    logs/            php.log, mail.log
    mail/            .eml-Dateien wenn kein SMTP konfiguriert ist
  .env.example
  .env               (lokal, gitignoriert)
  composer.json
```

## Lokales Setup (MAMP PRO)

1. **Composer-Abhängigkeiten installieren:**
   ```bash
   cd /Users/arminlorenz/Sites/localhost/gravelexplorer
   composer install
   ```

2. **Datenbank in MAMP PRO anlegen** (phpMyAdmin oder MAMP PRO → MySQL):
   ```sql
   CREATE DATABASE gravelexplorer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Die `.env` ist bereits auf die MAMP-Defaults (Port 8889, root/root, Socket-Pfad) vorkonfiguriert.

3. **Virtuellen Host in MAMP PRO einrichten** (empfohlen):
   - Host: `gravelexplorer.test`
   - DocumentRoot: `/Users/arminlorenz/Sites/localhost/gravelexplorer/public`
   - Eintrag in `/etc/hosts`: `127.0.0.1  gravelexplorer.test`
   - Web-Seiten dann erreichbar unter `http://gravelexplorer.test/login` etc.,
     API unter `http://gravelexplorer.test/api/v1/...`.

4. **Migration ausführen:**
   ```bash
   composer migrate
   # oder direkt: php public/index.php cli:migrate
   ```

   **Hinweis (M8):** MySQL committet DDL-Statements (`CREATE TABLE`, `ALTER TABLE`,
   …) **implizit**. Eine umschließende Transaktion bringt deshalb für eine
   Migration mit mehreren DDL-Statements keinen Schutz. Wenn eine Migration
   mittendrin failt:
   1. Prüfen, welche Statements bereits angekommen sind
      (`SHOW CREATE TABLE`, `SHOW INDEXES …`).
   2. Restliche Statements der Migration manuell anwenden **oder** das
      DB-Schema von Hand auf den Stand vor der Migration zurückbringen.
   3. Die Datei in `migrations/` ist bei einem Teilerfolg vom Migrator
      **nicht** in `migrations` als „erledigt" eingetragen — sie wird beim
      nächsten Lauf erneut versucht.

5. **Smoke-Test (Healthcheck):**
   ```bash
   curl http://gravelexplorer.test/healthz
   ```

## Produktions-Setup

- `.env` aus `.env.example` ableiten, `APP_ENV=production`, `APP_KEY` neu generieren
  (`php -r "echo base64_encode(random_bytes(32));"`), DB-Zugangsdaten,
  SMTP-Daten und `COOKIE_DOMAIN=gravelexplorer.benx.de` setzen.
- DocumentRoot des Vhosts muss auf `public/` zeigen.
- HTTPS muss aktiv sein. Die HTTPS-Weiterleitung in `public/.htaccess` ist
  vorbereitet und muss nur ent-kommentiert werden.
- Cleanup-Cron einrichten (z. B. stündlich):
  ```cron
  17 * * * * cd /var/www/gravelexplorer && /usr/bin/php public/index.php cron:cleanup >> storage/logs/cron.log 2>&1
  ```

## E-Mail-Versand

- Mit `MAIL_HOST` in der `.env` → echter Versand über PHPMailer/SMTP.
- Ohne `MAIL_HOST` → E-Mails werden als `.eml` in `storage/mail/` geschrieben
  (lokales Debugging). Einfach mit Mail.app/Thunderbird öffnen.

## API-Endpunkte

> **Vollständige, aktuelle API-Referenz (M1–M4):** [`docs/API.md`](docs/API.md)
> (Integrationsguide für die iOS-App) und [`openapi.yaml`](openapi.yaml)
> (OpenAPI 3.1). Die folgende Tabelle deckt nur Milestone 1 ab.

Alle Endpunkte unter `API_BASE_PATH` (Default `/api/v1`):

| Methode | Pfad                              | Auth   | Zweck |
|---------|-----------------------------------|--------|-------|
| POST    | `/auth/register`                  | nein   | Konto anlegen + Verify-Mail. **Antwortet immer 202** mit generischer Message — kein Auto-Login (Anti-Enumeration). Client muss nach Verify `/auth/login` aufrufen. |
| POST    | `/auth/login`                     | nein   | Einloggen |
| POST    | `/auth/refresh`                   | nein   | Access+Refresh rotieren |
| POST    | `/auth/logout`                    | Bearer | Aktuelle Session beenden |
| POST    | `/auth/logout-all`                | Bearer | Alle Sessions beenden |
| POST    | `/auth/password/change`           | Bearer | Passwort ändern |
| POST    | `/auth/password/forgot`           | nein   | Reset-Link anfordern (202) |
| POST    | `/auth/password/reset`            | nein   | Passwort via Token setzen |
| POST    | `/auth/email/verify`              | nein   | E-Mail per Token bestätigen |
| POST    | `/auth/email/verify/resend`       | optional | Verify-Mail erneut senden |
| GET     | `/users/me`                       | Bearer | Eigenes Profil |
| PATCH   | `/users/me`                       | Bearer | Profil ändern |
| DELETE  | `/users/me`                       | Bearer | Konto löschen |

Fehler-Envelope:
```json
{ "error": { "code": "validation_error", "message": "...", "fields": { "email": ["..."] } } }
```

## Web-Seiten

| Pfad               | Beschreibung |
|--------------------|--------------|
| `/login`           | Anmeldeformular |
| `/register`        | Registrierungsformular |
| `/forgot-password` | Passwort-vergessen-Formular |
| `/reset-password?token=...` | Neues Passwort festlegen |
| `/verify-email?token=...`   | E-Mail-Adresse bestätigen |
| `/dashboard`       | Geschütztes Dashboard (Platzhalter) |
| `/logout`          | (POST) Session beenden |

Alle POST-Formulare haben einen CSRF-Token (`_csrf`), der serverseitig in einer
PHP-Session gespeichert ist.

## Sicherheits-Notizen

- Passwörter mit Argon2id (`password_hash`), Rehash beim Login wenn nötig.
- Tokens (Refresh, Access, Reset, Verify) sind 32 zufällige Bytes (base64url).
  Nur ihr SHA-256-Hash wird gespeichert. Der Lookup erfolgt per `WHERE token_hash = ?`
  über einen Unique-Index — das ist auf DB-Ebene effektiv konstantzeitig, ein
  zusätzliches `hash_equals` ist hier nicht nötig. `hash_equals` kommt nur dort
  zum Einsatz, wo wirklich Klartext verglichen wird (CSRF-Token).
- Refresh-Tokens werden bei jedem `/auth/refresh` rotiert; die alten Access-
  Tokens der Session werden entwertet.
- Rate-Limiting fenster-basiert (Default 15 min) für `login`, `register`,
  `forgot-password`, `verify-resend` (per IP und/oder E-Mail).
- Cookies: `HttpOnly`, `SameSite=Lax`, `Secure` automatisch bei HTTPS bzw.
  `COOKIE_SECURE=true`.
- Alle SQL-Statements als Prepared Statements.

## Nicht im Scope dieses Milestones

- Routen-Upload/Sharing, Strava-OAuth, Crowd-Aggregation.
- Die DB-Schemas dafür werden in späteren Milestones mit zusätzlichen
  Migrationen hinzugefügt; das bestehende Schema bleibt kompatibel.
