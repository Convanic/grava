# Gamification Stufe 2 (Crews) — Design

**Status:** freigegeben (Brainstorming abgeschlossen 2026-06-20)
**Quelle:** `backend/GAME_STAGE2_BACKEND.md` (verbindlich), baut auf Stufe 1
(`backend/GAME_STAGE1_BACKEND.md`, `GAME_STAGE1_DASHBOARD.md`).
**Konzept:** `Gamification_Territory_Concept.md` §16. Bei Konflikt gewinnt das Konzeptpapier.

> **Ziel:** Fahrer gründen/treten **Crews** (neutrale Gruppen, Claimant-Typ `group`) bei. Die
> Präsenz der Mitglieder poolt sich auf die Crew → Crews erobern und halten Gebiet gemeinsam.
> Fraktionen (Stufe 3) sind **nicht** Teil dieser Spec.

---

## 1. Getroffene Entscheidungen (verbindlich)

1. **Beitritt: Präsenz wandert mit.** Tritt ein Fahrer einer Crew bei, zählen seine (im
   90-Tage-Fenster liegenden) Befahrungen **ab sofort für die Crew** — nicht erst ab Beitritt.
   Austritt → fallen wieder solo. Gelöst über „effektiven Claimant" (kein Daten-Backfill).
2. **Genau eine Crew** pro Fahrer (enforced per `PRIMARY KEY(user_id)` auf `game_crew_member`).
3. **Captain-Austritt: Übertragungs-Pflicht.** Ein Captain mit verbleibenden Mitgliedern kann die
   Crew nicht verlassen/wechseln/neu gründen → `409`. Er muss vorher per
   `POST /game/crews/transfer {user_id}` übertragen. Ist der Captain das **letzte** Mitglied, löst
   sein Leave die Crew auf.
4. **Recompute synchron** im Request (wie Stufe-1-Dashboard-Recompute) — sofort konsistent,
   keine Worker-Infra.
5. **Effektiver Claimant: PHP-Remap (Ansatz A).** `EdgeRecalculator` lädt eine Map
   `user_id → effektiver Claimant` und gruppiert die Pässe damit. Die gesamte Spiellogik (Hysterese,
   Gruppenfahrt-Bonus) bleibt in der getesteten PHP-Schicht; Live- und Full-Recompute nutzen
   denselben Code → Reproduzierbarkeit (Akzeptanz §8.8) trivial erfüllt.

---

## 2. Kernidee: Effektiver Claimant

Stufe 1 trägt den Besitz über `game_claimant` (`type ENUM('rider','group','faction')`). Eine Crew ist
ein Claimant vom Typ `group` (mit `user_id = NULL`; `UNIQUE(type,user_id)` erlaubt mehrere
Group-NULLs).

> **Effektiver Claimant eines Passes** = ist `game_edge_pass.user_id` aktuell Mitglied einer Crew →
> deren `group`-Claimant; sonst sein `rider`-Claimant.

Präsenz/Besitz werden **nicht** mehr über den gespeicherten `game_edge_pass.claimant_id` berechnet
(der ist ab jetzt historischer Stempel), sondern über den **effektiven Claimant zum
Berechnungszeitpunkt**.

### Präsenz (aktualisiert)
```
presence(effClaimant, edge)
   = Σ über alle (nicht invalidierten) Pässe auf der Kante, deren user_id auf effClaimant abbildet,
     von weight(pass)          # weight wie Stufe 1: max(0, 1 − age/window)
```
Tages-Deckel `UNIQUE(edge_id, user_id, ridden_on)` aus Stufe 1 bleibt — pro Fahrer max. 1 Pass/Tag.
Eine Crew aus N Mitgliedern, die alle dieselbe Kante an einem Tag fahren, bekommt N Beiträge
(gewünschter Gruppenfahrt-Effekt).

---

## 3. Datenmodell — `migrations/0017_game_crew.sql` (additiv)

### 3.1 `game_crew`
```sql
CREATE TABLE game_crew (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  claimant_id   BIGINT UNSIGNED NOT NULL,          -- FK game_claimant(type='group')
  name          VARCHAR(40)  NOT NULL,
  slug          VARCHAR(40)  NOT NULL,             -- ^[a-z0-9-]{3,40}$, eindeutig
  owner_user_id BIGINT UNSIGNED NOT NULL,          -- Gründer/Captain (denormalisiert; Rolle in member)
  join_code     CHAR(8)      NOT NULL,             -- Einladungscode
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_crew_claimant FOREIGN KEY (claimant_id) REFERENCES game_claimant(id) ON DELETE CASCADE,
  UNIQUE KEY uq_crew_slug (slug),
  UNIQUE KEY uq_crew_joincode (join_code)
);
```

### 3.2 `game_crew_member`
```sql
CREATE TABLE game_crew_member (
  user_id    BIGINT UNSIGNED NOT NULL PRIMARY KEY,   -- 1 Zeile/User ⇒ max. eine Crew
  crew_id    BIGINT UNSIGNED NOT NULL,
  role       ENUM('captain','member') NOT NULL DEFAULT 'member',
  joined_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT fk_member_crew FOREIGN KEY (crew_id) REFERENCES game_crew(id) ON DELETE CASCADE,
  KEY idx_member_crew (crew_id)
);
```

### 3.3 Config-Inserts (in derselben Migration)
```sql
INSERT INTO game_config (config_key, config_value) VALUES
  ('group_ride_bonus', '1.5'),
  ('group_ride_min_members', '3'),
  ('crew_max_members', '0')
ON DUPLICATE KEY UPDATE config_key = config_key;
```
Parallel `GameConfig::DEFAULTS` um dieselben drei Keys ergänzen (Fallback-Konsistenz).

---

## 4. Effektiver Claimant + Recompute (Ansatz A)

### 4.1 Repository (`GameRepository`)
- `effectiveClaimantMap(array $userIds): array` → `user_id => ['claimant_id'=>int,'is_group'=>bool]`.
  Eine Query: `game_claimant` (rider) `LEFT JOIN game_crew_member` `LEFT JOIN game_crew`; Crew-Mitglied
  → Group-Claimant (`is_group=true`), sonst Rider-Claimant. Legt **keine** Rider-Claimants an
  (Lesepfad-sicher; im Recompute existieren sie bereits, da Pässe sie referenzieren).
- `effectiveClaimantId(int $userId): int` (Einzelfall für Endpunkte/Tests; legt Rider-Claimant bei
  Bedarf an wie `riderClaimantId`).
- `passesForEdge()` liefert zusätzlich `ridden_on` (für den Tagesbonus).

### 4.2 `EdgeRecalculator::recalculate()` umgestellt
1. `passesForEdge($edgeId)` (mit `ridden_on`).
2. `user_id`s sammeln → `effectiveClaimantMap`.
3. Präsenz **nach effektivem Claimant** gruppieren (statt nach gespeichertem `claimant_id`).
4. Owner-Entscheidung/Hysterese/Wert/Frische unverändert (Stufe-1-Logik), nur die Gruppierung
   wechselt. `discoverer_claimant_id`/Pionier bleiben per-User (Wert ändert sich **nicht** durch
   Crew, nur **Besitz**).

### 4.3 Gruppenfahrt-Bonus (§4.2 der Spec)
In `EdgeRecalculator`, auf der gruppierten Tagesstruktur:
- Pässe nach `(effClaimant, ridden_on)` gruppieren. Pro Tag:
  `dayWeight = Σ presenceWeight(pass)`, `distinctMembers = COUNT(DISTINCT user_id)`.
- Wenn Claimant **Group** ist **und** `distinctMembers ≥ group_ride_min_members` **und**
  `group_ride_bonus ≠ 1.0` → `dayWeight *= group_ride_bonus`.
- `presence[claimant] += dayWeight`.
- Rider-Claimants: pro Tag immer genau 1 Member → Bonus greift nie; zusätzlich explizit auf
  `is_group` beschränkt (kein Misconfig-Footgun). Default-neutral, wenn nicht aktiviert.

### 4.4 Recompute-Trigger bei Mitgliedschaftsänderung (Spec §4.1)
Bei join/leave/create:
1. Betroffene Kanten: `SELECT DISTINCT edge_id FROM game_edge_pass WHERE user_id=? AND invalidated_at IS NULL AND ridden_on >= (heute − presence_window_days)`.
2. Pro Kante `EdgeRecalculator::recalculate` (synchron).
3. Audit (`game_audit`, s. §5).
- **`transfer` ändert keinen effektiven Claimant → KEIN Recompute** (nur Rollenwechsel).

---

## 5. Endpunkte (`/api/v1/game/crews`, Bearer)

Neuer `CrewController` (Api) + `CrewService` (Logik/Recompute/Audit) + `CrewRepository` (CRUD).

| Methode | Pfad | Verhalten |
|---|---|---|
| POST | `/game/crews` | `{name}` → Group-Claimant + Crew anlegen, Gründer=`captain`. Verlässt evtl. alte Crew zuerst (Captain-Regel gilt). Liefert Crew inkl. `join_code`. |
| GET | `/game/crews/{slug}` | Crew-Profil: Name, Slug, Mitgliederzahl, gehaltene Kanten/Länge (`meStats` auf Group-Claimant), Captain-Handle. |
| POST | `/game/crews/join` | `{join_code}` → beitreten; verlässt alte Crew zuerst; `crew_max_members` prüfen (0=unbegrenzt). |
| POST | `/game/crews/leave` | aktuelle Crew verlassen (Captain-Regel, s.u.). |
| POST | `/game/crews/transfer` | `{user_id}` → Captain überträgt Captain-Rolle (nur Captain; Ziel muss Mitglied sein). Aktualisiert `member.role` **und** `game_crew.owner_user_id`. Kein Recompute. |
| GET | `/game/crews/me` | eigene Crew + Mitglieder + Aggregat, oder `null` wenn solo. |

### 5.1 Captain-Regel & Auflösung
- Captain mit verbleibenden Mitgliedern: leave/join/create → `409` (`captain_must_transfer`).
- Captain als **letztes** Mitglied: leave löst die Crew auf.
- **FK-sichere Reihenfolge bei Auflösung** (`fk_edge_owner` referenziert den Group-Claimant): **erst**
  Recompute der betroffenen Kanten (Owner wandert vom Group-Claimant weg), **dann** Crew + Group-Claimant
  löschen (`game_crew` ON DELETE CASCADE räumt Member; Claimant separat löschen).

### 5.2 Slug & Join-Code
- `slug`: aus `name` slugifiziert (`^[a-z0-9-]{3,40}$`), bei Kollision numerisches Suffix.
- `join_code`: 8 Zeichen, kollisionsarmes Alphabet (keine 0/O/1/I), Retry bei Unique-Konflikt.

### 5.3 Owner-JSON additiv (Spec §5.1)
`GameRepository::claimantInfo()` erweitern → `{claimant_id, type, handle, name}`:
- `rider`: `handle`=`users.public_handle`, `name`=`display_name`|null.
- `group`: per Join auf `game_crew` → `handle`=slug, `name`=Crew-Name.
Wirkt automatisch in `/game/edges` und `/game/edges/{id}` (beide nutzen `formatEdge`→`claimantInfo`).
**Additiv** — bricht den bestehenden iOS-Decoder nicht (`name` optional).

### 5.4 Audit
`game_audit` mit `admin_user_id`=handelnder User (Spalte ohne FK → als Actor genutzt),
`action`∈`crew_create|crew_join|crew_leave|crew_transfer`, `target`=Slug, `detail_json` optional.

---

## 6. Config-Ergänzungen (`game_config` + `GameConfig::DEFAULTS`)
| key | default | Bedeutung |
|---|---|---|
| `group_ride_bonus` | 1.5 | Tagesfaktor bei Gruppenfahrt |
| `group_ride_min_members` | 3 | ab wie vielen verschiedenen Mitgliedern/Tag/Kante der Bonus greift |
| `crew_max_members` | 0 | 0 = unbegrenzt; sonst Obergrenze beim Join/Create |

---

## 7. Akzeptanzkriterien (als Tests) + `GAME_STAGE2_TESTREPORT.md`
Deterministisch (Fake-Zeit/Fixtures wie Stufe 1):
1. **Genau eine Crew:** zweiter Join wechselt sauber (verlässt alte zuerst); `game_crew_member` nie
   2 Zeilen pro `user_id`.
2. **Präsenz wandert mit (Beitritt):** Fahrer1 besitzt E solo → tritt Crew C bei → nach Recompute
   Besitzer(E)=C, ohne neue Fahrt.
3. **Austritt:** Fahrer1 verlässt C → E gehört wieder Rider-Claimant(Fahrer1).
4. **Crew schlägt Solo durch Mitgliederzahl:** 3 Crew-Mitglieder (je 1 Pass/Tag) > Solo-Vielfahrer
   (Tages-Deckel); Übernahme mit Hysterese (1.15).
5. **Gruppenfahrt-Bonus:** `bonus=1.5, min=3`: 3 Mitglieder/Tag → Crew-Tagesbeitrag ×1.5; bei 2
   Mitgliedern kein Bonus.
6. **Owner-JSON:** `/game/edges` für crew-eigene Kante → `owner.type="group"` + `name`.
7. **Recompute-Umfang:** Join/Leave rechnet genau die Fenster-Kanten des Users neu (nicht den
   ganzen Graphen).
8. **Reproduzierbarkeit:** voller `game:recompute` == inkrementeller Pfad (inkl. Crew-Zuordnung).
9. **Captain-Regel:** Captain-Leave mit Mitgliedern → 409; nach transfer ok; Captain als Letzter →
   Crew aufgelöst (Group-Claimant weg, Kanten zurück auf Rider).

---

## 8. Definition of Done
- [ ] `game_crew`, `game_crew_member` migriert; Crew-Claimant via `game_claimant(type='group')`.
- [ ] Präsenz/Besitz über effektiven Claimant (EdgeRecalculator umgestellt), invalidierte Pässe weiter
      ausgeschlossen.
- [ ] Endpunkte §5 inkl. Join/Leave/Create-Recompute (§4.4) + Captain-Regel + Audit.
- [ ] `owner.name` additiv in `/game/edges` + `/game/edges/{id}`.
- [ ] Gruppenfahrt-Bonus hinter `group_ride_bonus` (default-neutral, wenn < min_members).
- [ ] Akzeptanztests §7 grün + `GAME_STAGE2_TESTREPORT.md`.

## 9. Out of Scope (bewusst)
- Dashboard-Crew-Verwaltung (Spec §6) — späterer Nachzug, nicht iOS-blockierend.
- Fraktionen / Stufe 3.
- Admin-Übersichtskarte (`edgesGeoForMap`): Crew-Name-Anzeige für Group-Owner — optionaler Nachzug
  mit dem Dashboard.
