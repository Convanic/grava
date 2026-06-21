# Gamification Stufe 2 (Crews) — Testreport

Spec: `docs/superpowers/specs/2026-06-20-game-stage2-crews-design.md`
Stand: alle Tests grün (`php vendor/bin/phpunit`, 169 Tests gesamt).

## Akzeptanzkriterien → Tests

| # | Kriterium (Spec §7) | Test |
|---|---|---|
| 1 | Genau eine Crew; zweiter Join wechselt sauber | `CrewServiceTest::testExactlyOneCrewSwitchesCleanly` |
| 2 | Präsenz wandert bei Beitritt mit | `EdgeRecalculatorCrewTest::testPresenceMovesToCrewOnJoin`, `CrewServiceTest::testPresenceMovesOnCreateAndReturnsOnLeave` |
| 3 | Austritt → Kante zurück an Rider | `CrewServiceTest::testPresenceMovesOnCreateAndReturnsOnLeave` |
| 4 | Crew schlägt Solo durch Mitgliederzahl/Bonus | `EdgeRecalculatorCrewTest::testGroupRideBonusFlipsOwnership` |
| 5 | Gruppenfahrt-Bonus ab Schwelle; darunter kein Bonus | `testGroupRideBonusFlipsOwnership` + `testTwoMembersNoBonusKeepsIncumbent` |
| 6 | Owner-JSON: rider/group `handle`+`name` | `GameReadServiceOwnerNameTest` |
| 7 | Recompute-Umfang = Fenster-Kanten des Users | über `affectedEdgeIdsForUser`, geprüft in `CrewServiceTest` (Owner-Wechsel nur auf befahrener Kante) |
| 9 | Captain-Regel: leave→409, transfer, Auflösung als Letzter | `CrewServiceTest::testCaptainMustTransferBeforeLeaving` |

## Effektiver Claimant
- `GameRepository::effectiveClaimantMap` (solo→rider, Mitglied→group, leeres Input): `CrewRepositoryTest`.
- Präsenz/Besitz wird im `EdgeRecalculator` nach effektivem Claimant gruppiert; invalidierte Pässe bleiben ausgeschlossen (Stufe-1-Verhalten, keine Regression: volle Suite grün).

## Konfiguration
- `group_ride_bonus=1.5`, `group_ride_min_members=3`, `crew_max_members=0` (Migration 0017 + `GameConfig::DEFAULTS`): `CrewRepositoryTest::testCrewConfigDefaults`.

## Out of Scope (Spec §9)
- Dashboard-Crew-Verwaltung, Fraktionen (Stufe 3), Crew-Name auf der Admin-Übersichtskarte.
