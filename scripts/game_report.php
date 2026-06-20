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
    . "| 10.10 | BBox-Read | tests/Integration/Game/GameReadServiceTest::testBboxReturnsEdgeInsideAndOmitsOutside |\n";

file_put_contents(__DIR__ . '/../backend/GAME_STAGE1_TESTREPORT.md', $report);
echo "Testbericht geschrieben: backend/GAME_STAGE1_TESTREPORT.md\n";
echo implode("\n", $rows) . "\n";
