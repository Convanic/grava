# Offene Review-Findings (deferred)

Diese Findings aus dem M1-Code-Review sind bewusst nicht in der
Critical-/High-/Medium-/Polish-Behebung umgesetzt, weil sie entweder
ein Produkt-/UX-Trade-off, einen größeren Refactor oder eine externe
Abhängigkeit voraussetzen. Sie sollten vor M2-Abschluss neu bewertet
werden.

| Finding | Severity | Warum deferred | Mögliche Lösung |
|---|---|---|---|
| **H5** Refresh-Cookie auf `path=/` | High | Saubere Lösung verlangt eine separate Web-Session (z. B. PHP-`$_SESSION` mit `user_id`) statt die Refresh-Token-Rotation auf jedem Pageload. Das ist ein Architektur-Wechsel, der in den M2-Umbau gehört. | Web-Auth über Server-side Session-Cookie (kurzes Lifetime, Server entscheidet); `ge_refresh` nur an `/api/v1/auth/refresh` und an einen dedizierten `/auth/web-refresh`-Endpoint scopen. |
| **M5** `email_verified_at` wird nicht erzwungen | Medium | Produktentscheidung: was darf ein unverified User? In M1 ist die Antwort „alles, weil sonst kann er nicht erst mal ankommen". Konkrete Sperren (z. B. „kein Routen-Upload ohne Verify") gehören zur jeweiligen Feature-Story. | In jedem geschützten Endpunkt (oder als Middleware-Variante `RequireVerified`) prüfen und 403 mit `email_verification_required` antworten. README: Politikbeschreibung. |
| **L3** `Response::*` mit `exit;` macht Tests schwer | Low | Ist ein größerer Refactor: Response als Objekt aus dem Front-Controller emittieren. Eher M2/M3-Scope. | PSR-7-artige Response-Klasse plus zentrale Emit-Funktion in `public/index.php`. |
| **L10** `Csrf` als statisches Modul | Low | Statisches Singleton-Verhalten erschwert Tests. Echter Refactor zur Instance-API. | DI-Variante mit `CsrfMiddleware` als Service, Token-Storage über injizierten Session-Wrapper. |

## Erledigt im Quick-Wins-Branch (`polish/quick-wins`, 2026-06-17)

- **M7** — Top-1000-Passwortliste aus SecLists (`Pwdb_top-1000`) in
  `data/common-passwords.txt` eingebettet, Validator lädt lazy mit
  Hash-Set-Cache. Fallback auf eingebettete 14er-Liste bei Dateifehlern.
- **M8** — README ergänzt um Hinweis auf implizite DDL-Commits in MySQL
  und Wiederherstellungs-Schritte bei fehlgeschlagener Migration.
- **L9** — `Config::require()` umbenannt in `Config::requireValue()`
  (zwei Caller in `Db.php` mitgezogen).
- **L14** — `Request::clientIp()` extrahiert in `Support\Ip` mit
  pure-function-Variante `Ip::resolve()` für Tests.

## Bekannte Architektur-Anmerkungen

- **Web-Auth-Architektur (siehe H5/M4)**: Heute teilen sich Web und API
  denselben Refresh-Token-Pool. Der Web-Path nutzt CookieAuth, der API-Path
  liest aus dem JSON-Body. Das ist pragmatisch, aber führt dazu, dass jeder
  Page-Load eine Refresh-Token-Rotation triggern kann. Für M2 ist eine
  Trennung sinnvoll: Web → Server-side Session-Cookie; API → Bearer.
- **Mail-Versand**: Solange `MAIL_HOST` leer ist, schreibt der MailService
  in `storage/mail/*.eml`. Das ist ein Dev-Komfort und keine
  Production-Strategie — vor Beta unbedingt SMTP konfigurieren UND
  einen sicheren Weg vorsehen, dass ein leerer `MAIL_HOST` in Production
  nicht stillschweigend in den Disk-Fallback rutscht (z. B. Hard-Fail
  in `MailService::send`, wenn `Config::isProduction() && MAIL_HOST === ''`).
