<?php
declare(strict_types=1);

// Erzeugt backend/GAME_STAGE1_TESTREPORT.md: Pionier-Golden-Tabelle +
// Mapping der Akzeptanzkriterien (§10) auf die zugehoerigen Tests.
require __DIR__ . '/../vendor/autoload.php';

use App\Game\GameMath;

$rows = [];
foreach ([1, 5, 10, 12, 20, 30] as $n) {
    $rows[] = sprintf('| %d | %.2f |', $n, GameMath::pioneer($n, 100.0, 12.0, 4.0));
}

$report = "# Gamification Stufe 1 — Testbericht\n\n"
    . "Generiert: " . gmdate('Y-m-d\TH:i:s\Z') . "\n\n"
    . "## Pionier-Golden-Tabelle (P0=100, k=12, s=4)\n\n"
    . "| n | pioneer(n) |\n|---|---|\n" . implode("\n", $rows) . "\n\n"
    . "## Akzeptanzkriterien → Tests\n\n"
    . "| § | Kriterium | Test |\n|---|---|---|\n"
    . "| 10.1 | Pionier-Formel | tests/Unit/Game/GameMathTest::testPioneerGoldenNumbers |\n"
    . "| 10.2 | Praesenz-Verfall | tests/Unit/Game/GameMathTest::testPresenceWeightLinearDecay |\n"
    . "| 10.3 | Wert-Verknuepfung | tests/Unit/Game/GameMathTest::testValueAtFirstRiderIsPioneer + testValueAtManyRidersIsPopularity + testCurationAddsOnTop |\n"
    . "| 10.4 | Ingest -> Besitz | tests/Integration/Game/GameIngestionTest::testIngestGivesOwnershipToFirstRider |\n"
    . "| 10.5 | Tages-Deckel + Recompute | GameIngestionTest::testReingestSameRouteSameDayCreatesNoDuplicatePasses + GameRecomputeTest::testFullRecomputeMatchesLivePath |\n"
    . "| 10.6 | Pionier-Abfall (12 Fahrer) | GameIngestionTest::testTwelveDistinctRidersDropPioneerToFifty |\n"
    . "| 10.7 | Hysterese | tests/Integration/Game/EdgeRecalculatorTest::testHysteresisKeepsOwnerUntilExceeded |\n"
    . "| 10.8 | Authentizitaet | GameIngestionTest::testAuthFiltersRejectSlowAndInaccuratePasses |\n"
    . "| 10.9 | Valhalla-Ausfall | GameIngestionTest::testMatcherFailureLeavesNoDataButReingestRecovers |\n"
    . "| 10.10 | BBox-Read | tests/Integration/Game/GameReadServiceTest::testBboxReturnsEdgeInsideAndOmitsOutside |\n\n"
    . "## Dashboard (Stufe 1) — Akzeptanz §5\n\n"
    . "Mapping der Akzeptanzkriterien aus `backend/GAME_STAGE1_DASHBOARD.md` (§5, 1–8) auf die implementierenden Tests.\n\n"
    . "| § | Kriterium | Test |\n|---|---|---|\n"
    . "| 5.1 | Zugriffsschutz/Host-Gate | tests/Unit/Game/Admin/AdminGuardTest + tests/Unit/Game/Admin/AdminHostTest (+ Host-Gate in public/index.php) |\n"
    . "| 5.2 | Config-Update + Audit + Validierung | tests/Integration/Game/Admin/GameConfigAdminServiceTest |\n"
    . "| 5.3 | Recompute identisch zum Live-Pfad | tests/Integration/Game/GameRecomputeBboxTest (+ Backend §10.5 GameRecomputeTest) |\n"
    . "| 5.4 | Ingest-Monitor (failed→reingest→ok) | tests/Integration/Game/GameIngestBanLogTest (Ingest-Log) + Controller reingest |\n"
    . "| 5.5 | Pass-Invalidierung wirkt (+ Reaktivieren) | tests/Integration/Game/Admin/GamePassAdminServiceTest + tests/Integration/Game/GameInvalidationTest |\n"
    . "| 5.6 | Ban-Effekt (keine neuen Pässe) | tests/Integration/Game/GameIngestBanLogTest + tests/Integration/Game/Admin/GameUserFlagServiceTest |\n"
    . "| 5.7 | Leaderboard-Aggregate | tests/Integration/Game/Admin/GameAdminServiceTest |\n"
    . "| 5.8 | Inspector-Wertaufschlüsselung (Golden Number n=12 → pioneer 50.0) | tests/Integration/Game/Admin/GameAdminServiceTest |\n";

file_put_contents(__DIR__ . '/../backend/GAME_STAGE1_TESTREPORT.md', $report);
echo "Testbericht geschrieben: backend/GAME_STAGE1_TESTREPORT.md\n";
echo implode("\n", $rows) . "\n";
