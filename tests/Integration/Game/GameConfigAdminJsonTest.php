<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\Admin\GameAuditService;
use App\Game\Admin\GameConfigAdminService;
use App\Game\GameConfig;
use Tests\IntegrationTestCase;

/**
 * Integration: die Progression-JSON-Parameter (Ränge/Abzeichen) sind über den
 * Admin-Config-Service pflegbar — gültiges JSON wird VOLLSTÄNDIG gespeichert
 * (verbreiterte Spalte, Migration 0039), ungültiges abgelehnt.
 */
final class GameConfigAdminJsonTest extends IntegrationTestCase
{
    private GameConfigAdminService $svc;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new GameConfigAdminService(
            $this->pdo,
            new GameConfig($this->pdo),
            new GameAuditService($this->pdo),
        );
        $this->adminId = $this->createUser('admin');
    }

    public function testLongCatalogJsonIsStoredInFull(): void
    {
        // Realistischer Katalog (~390 Zeichen) — sprengt das alte VARCHAR(64).
        $catalog = '{"erschliesser":{"core":true,"tiers":[25,250,1500,6000,25000]},'
            . '"revierhalter":{"core":true,"tiers":[10,100,400,1000,3000]},'
            . '"kondition":{"core":true,"tiers":[50,500,2500,10000,40000]},'
            . '"stammfahrer":{"core":true,"tiers":[2,8,26,52,104]},'
            . '"schnellster":{"core":false,"tiers":[1,5,20,50,150]},'
            . '"crew":{"core":false,"tiers":[1,5,20,50,150]},'
            . '"challenger":{"core":false,"tiers":[1,10,40,120,365]}}';
        $this->assertGreaterThan(64, strlen($catalog), 'Testwert muss das alte Limit übersteigen');

        $errors = $this->svc->update($this->adminId, ['progression_catalog' => $catalog]);
        $this->assertSame([], $errors);

        // Frische GameConfig (eigener Cache) liest den DB-Wert zurück — vollständig.
        $stored = (new GameConfig($this->pdo))->string('progression_catalog');
        $this->assertSame($catalog, $stored);
    }

    public function testTrafficPenaltyParamsAreEditable(): void
    {
        // Vor dem Fix wurden Traffic-Keys als „unbekannt" still verworfen.
        $errors = $this->svc->update($this->adminId, [
            'traffic_f_min' => '0.5',   // tieferer Abschlag für dicht befahrene Straßen
            'traffic_t0'    => '8.0',
        ]);
        $this->assertSame([], $errors);

        $cfg = new GameConfig($this->pdo);
        $this->assertSame(0.5, $cfg->float('traffic_f_min'));
        $this->assertSame(8.0, $cfg->float('traffic_t0'));

        // Negativ → abgelehnt.
        $bad = $this->svc->update($this->adminId, ['traffic_f_min' => '-1']);
        $this->assertArrayHasKey('traffic_f_min', $bad);
    }

    public function testInvalidJsonIsRejected(): void
    {
        $errors = $this->svc->update($this->adminId, ['progression_rank_ap' => '[0,100,']);
        $this->assertArrayHasKey('progression_rank_ap', $errors);

        // Und nichts wurde geschrieben (Default greift weiter).
        $stored = (new GameConfig($this->pdo))->string('progression_rank_ap');
        $this->assertStringContainsString('[0,100,400', $stored); // unveränderter Default
    }
}
