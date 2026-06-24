# Follower-/Following-Listen — Backend-Anweisung

**Audience:** Cursor / Backend-Agent. Die Client-Seite ist gebaut: `CommunityStore` lädt die Listen,
`UserListView` rendert sie (Avatar/Handle/Display-Name + Folgen-Button je Zeile) und wartet auf genau
diese zwei Lese-Endpunkte. Muster wie die bestehenden Profil-Endpunkte (`/users/by-handle/{handle}`):
reiner Lookup beim Lesen, OptionalBearer, bidirektionaler Block-Schutz, kein Recompute.

## Endpunkte
**`GET /api/v1/users/by-handle/{handle}/followers`** — wer **diesem** User folgt.
**`GET /api/v1/users/by-handle/{handle}/following`** — wem **dieser** User folgt.

Beide **OptionalBearer** (wie `GET /users/by-handle/{handle}`): anonym abrufbar; ein gültiger Bearer
ergänzt nur die viewer-relativen Felder (`is_self`, `is_followed_by_viewer`) und aktiviert den
Block-Filter aus Sicht des Anfragenden.

> Namens-Hinweis: Die bestehenden **eigenen** Listen heißen `GET /users/me/follows` /
> `GET /users/me/followers` und liefern eine **reduzierte** Form (`handle`, `display_name`,
> `followed_at`). Die neuen Endpunkte sind bewusst **anders**: beliebiger `{handle}` (nicht nur „me")
> und volle `PublicProfile`-Objekte. Bitte die alten `/me/*`-Endpunkte unverändert lassen.

### Query-Parameter
| Param | Typ | Default | Bedeutung |
|---|---|---|---|
| `limit` | int | `50` | Seitengröße, server-seitig auf `1..100` geklemmt. |
| `offset` | int | `0` | Offset für Paging, auf `>= 0` geklemmt. |

Sortierung: neueste Follow-Beziehung zuerst (`follows.created_at DESC`), bei Gleichstand deterministisch
(z. B. nach `user_id`).

## Datenmodell-Hinweis — **keine Migration nötig**
Nutzt die bestehende Follow-Beziehung `follows (follower_id, followee_id, created_at)` und `users`
(`public_handle`, `display_name`, `status`). Es kommen **keine** neuen Tabellen/Spalten dazu.

- `…/followers` = `SELECT … FROM follows f JOIN users u ON u.id = f.follower_id WHERE f.followee_id = {profil}`.
- `…/following` = `SELECT … FROM follows f JOIN users u ON u.id = f.followee_id WHERE f.follower_id = {profil}`.
- Wie in `FollowService::listFollowers/listFollowees` immer filtern: `u.public_handle IS NOT NULL AND u.status = 'active'`.

Optional (Performance, falls noch nicht vorhanden): Indizes
`follows(followee_id, created_at)` und `follows(follower_id, created_at)` decken beide Richtungen
sortiert ab. Reine Lese-Optimierung, kein Datenmodell-Eingriff.

## Antwort
Response = das bestehende **`PublicProfile`-Schema** als `users[]` plus optionale `pagination`
(gleiche Form wie bei `GET /users/by-handle/{handle}/routes`).

```json
{
  "users": [
    {
      "handle": "lea",
      "display_name": "Lea",
      "joined_at": "2025-04-02T10:15:00Z",
      "route_count_public": 12,
      "follower_count": 48,
      "following_count": 33,
      "is_followed_by_viewer": true,
      "is_self": false
    }
  ],
  "pagination": { "limit": 50, "offset": 0, "total": 137, "has_more": true }
}
```

### Feld-Vertrag (pro User-Objekt = identisch zu `GET /users/by-handle/{handle}` → `user`)
| Feld | Typ | Hinweis |
|---|---|---|
| `handle` | string | `public_handle`, immer gesetzt (Nicht-Handles sind aus der Liste gefiltert). |
| `display_name` | string \| null | nullable. |
| `joined_at` | string (ISO-8601, `…Z`) | aus `users.created_at`. |
| `route_count_public` | int | Anzahl öffentlicher, nicht gelöschter Routen. |
| `follower_count` | int | Follower des gelisteten Users. |
| `following_count` | int | wem der gelistete User folgt. |
| `is_followed_by_viewer` | bool \| null | `null` bei anonymem Viewer; sonst ob der Anfragende dieser Zeile folgt. |
| `is_self` | bool | `true`, wenn die Zeile der Anfragende selbst ist (nur mit Bearer). |

`pagination`: `{ limit, offset, total, has_more }`; `has_more = (offset + limit) < total`. Felder additiv
— bitte alle mitsenden, damit `UserListView` direkt rendern und `CommunityStore` seitenweise nachladen kann.

## Datenschutz
- **Profil-Sichtbarkeit:** Existiert `{handle}` nicht, ist inaktiv oder hat keinen `public_handle` → **404**
  (gleiche `not_found`-Antwort wie beim Profil-Endpunkt, kein Existenz-Probing).
- **Bidirektionaler Block (Profil ↔ Viewer):** Hat der Viewer den Profil-User geblockt **oder** umgekehrt,
  liefert der Endpunkt **404** — **bevor** die Liste zusammengebaut wird (analog `ProfileService::getProfile`).
- **Block-Filter innerhalb der Liste:** Bei vorhandenem Bearer Zeilen ausblenden, in denen ein
  Block-Verhältnis zwischen dem **Viewer** und dem gelisteten User besteht (in beide Richtungen) —
  konsistent mit der Discovery-Block-Liste. `total` zählt die gefilterte Sicht.
- **Nur Public-Daten:** Es werden ausschließlich `PublicProfile`-Felder ausgegeben; keine E-Mail,
  keine internen IDs, keine privaten/unlisted Routen.

## Edge Cases
- **Unbekannter/inaktiver Handle:** 404.
- **Block (Profil ↔ Viewer):** 404 (kein 403, damit Blocks nicht aus dem Status ablesbar sind).
- **Leere Liste:** 200 mit `users: []` und `pagination.total = 0` (kein Fehler).
- **`offset` jenseits von `total`:** 200 mit leerer Seite, `has_more = false`.
- **Ungültige `limit`/`offset`:** still auf gültigen Bereich klemmen (kein 422 nötig).

## Akzeptanzkriterien
1. `GET …/followers` und `GET …/following` (anonym) → 200 mit `users[]` im `PublicProfile`-Schema; `is_self=false`, `is_followed_by_viewer=null` in allen Zeilen.
2. Mit Bearer → `is_followed_by_viewer` korrekt pro Zeile; die eigene Zeile (falls in der Liste) hat `is_self=true`.
3. Unbekannter/inaktiver/handle-loser Profil-User → 404 (`not_found`); Block (Profil ↔ Viewer) → ebenfalls 404.
4. `limit`/`offset` pagen korrekt; `pagination.total` und `has_more` stimmen mit der (block-gefilterten) Gesamtmenge überein.
5. Leere Beziehung → 200 mit `users: []`, `total=0`, nie 500; Sortierung neueste Beziehung zuerst, deterministisch bei Gleichstand.
6. Reiner Lese-Lookup über die bestehende `follows`-Beziehung — keine Migration, keine `/me/*`-Endpunkte verändert; nur Public-Felder ausgegeben.

## Client-Erwartung
`CommunityStore` ruft `…/followers` bzw. `…/following` mit `limit`/`offset` ab und lädt über
`pagination.has_more` seitenweise nach. `UserListView` zeigt je Zeile Handle/Display-Name und nutzt
`is_followed_by_viewer`/`is_self` für den Folgen-Button-Zustand (eigene Zeile ohne Button). Beide Views
sind fertig und warten nur auf diese zwei Endpunkte.
