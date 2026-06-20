# Gamification Stufe 1 — Testbericht

Generiert: 2026-06-20T15:59:24Z

## Pionier-Golden-Tabelle (P0=100, k=12, s=4)

| n | pioneer(n) |
|---|---|
| 1 | 100.00 |
| 5 | 97.07 |
| 10 | 67.46 |
| 12 | 50.00 |
| 20 | 11.47 |
| 30 | 2.50 |

## Akzeptanzkriterien → Tests

| § | Kriterium | Test |
|---|---|---|
| 10.1 | Pionier-Formel | tests/Unit/Game/GameMathTest::testPioneerGoldenNumbers |
| 10.2 | Praesenz-Verfall | tests/Unit/Game/GameMathTest::testPresenceWeightLinearDecay |
| 10.3 | Wert-Verknuepfung | tests/Unit/Game/GameMathTest::testValueAtFirstRiderIsPioneer + testValueAtManyRidersIsPopularity + testCurationAddsOnTop |
| 10.4 | Ingest -> Besitz | tests/Integration/Game/GameIngestionTest::testIngestGivesOwnershipToFirstRider |
| 10.5 | Tages-Deckel + Recompute | GameIngestionTest::testReingestSameRouteSameDayCreatesNoDuplicatePasses + GameRecomputeTest::testFullRecomputeMatchesLivePath |
| 10.6 | Pionier-Abfall (12 Fahrer) | GameIngestionTest::testTwelveDistinctRidersDropPioneerToFifty |
| 10.7 | Hysterese | tests/Integration/Game/EdgeRecalculatorTest::testHysteresisKeepsOwnerUntilExceeded |
| 10.8 | Authentizitaet | GameIngestionTest::testAuthFiltersRejectSlowAndInaccuratePasses |
| 10.9 | Valhalla-Ausfall | GameIngestionTest::testMatcherFailureLeavesNoDataButReingestRecovers |
| 10.10 | BBox-Read | tests/Integration/Game/GameReadServiceTest::testBboxReturnsEdgeInsideAndOmitsOutside |

## Dashboard (Stufe 1) — Akzeptanz §5

Mapping der Akzeptanzkriterien aus `backend/GAME_STAGE1_DASHBOARD.md` (§5, 1–8) auf die implementierenden Tests.

| § | Kriterium | Test |
|---|---|---|
| 5.1 | Zugriffsschutz/Host-Gate | tests/Unit/Game/Admin/AdminGuardTest + tests/Unit/Game/Admin/AdminHostTest (+ Host-Gate in public/index.php) |
| 5.2 | Config-Update + Audit + Validierung | tests/Integration/Game/Admin/GameConfigAdminServiceTest |
| 5.3 | Recompute identisch zum Live-Pfad | tests/Integration/Game/GameRecomputeBboxTest (+ Backend §10.5 GameRecomputeTest) |
| 5.4 | Ingest-Monitor (failed→reingest→ok) | tests/Integration/Game/GameIngestBanLogTest (Ingest-Log) + Controller reingest |
| 5.5 | Pass-Invalidierung wirkt (+ Reaktivieren) | tests/Integration/Game/Admin/GamePassAdminServiceTest + tests/Integration/Game/GameInvalidationTest |
| 5.6 | Ban-Effekt (keine neuen Pässe) | tests/Integration/Game/GameIngestBanLogTest + tests/Integration/Game/Admin/GameUserFlagServiceTest |
| 5.7 | Leaderboard-Aggregate | tests/Integration/Game/Admin/GameAdminServiceTest |
| 5.8 | Inspector-Wertaufschlüsselung (Golden Number n=12 → pioneer 50.0) | tests/Integration/Game/Admin/GameAdminServiceTest |
