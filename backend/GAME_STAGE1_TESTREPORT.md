# Gamification Stufe 1 — Testbericht

Generiert: 2026-06-20T14:48:55Z

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
