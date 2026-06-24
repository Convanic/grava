<?php
declare(strict_types=1);

namespace Tests\Integration\Game;

use App\Game\EdgeRecalculator;
use App\Game\FakeEdgeMatcher;
use App\Game\GameConfig;
use App\Game\GameIngestionService;
use App\Game\GameRepository;
use App\Game\MatchedSegment;
use App\Game\SegmentSpeedService;
use App\Routes\GeometryParser;
use App\Routes\ParsedRoute;
use DateTimeImmutable;
use DateTimeZone;
use Tests\IntegrationTestCase;

/**
 * Deckt die Akzeptanzkriterien aus backend/GAME_SEGMENT_SPEED_BACKEND.md ab.
 */
final class SegmentSpeedTest extends IntegrationTestCase
{
    private GameRepository $repo;
    private GameConfig $config;
    private SegmentSpeedService $speed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new GameRepository($this->pdo);
        $this->config = new GameConfig($this->pdo);
        $this->speed = new SegmentSpeedService($this->repo, $this->config);
    }

    private function parsedRoute(): ParsedRoute
    {
        return (new GeometryParser())->parse(
            '{"type":"LineString","coordinates":[[9.65,47.12],[9.66,47.13],[9.67,47.14]]}'
        );
    }

    /** Segment mit einstellbarer Länge/Tempo/Timing für die Effort-Gates. */
    private function segment(
        float $lengthM = 500.0,
        ?float $avgSpeedKmh = 18.0,
        bool $hasMotion = true,
        string $at = '2026-06-18 09:00:00',
        ?float $durationS = null,
    ): MatchedSegment {
        return new MatchedSegment(
            wayId: 1001, nodeARef: 10, nodeBRef: 11, lengthM: $lengthM,
            geometry: [[9.65, 47.12], [9.66, 47.13]], surface: 'gravel',
            avgSpeedKmh: $avgSpeedKmh, maxHaccM: 8.0, hasMotion: $hasMotion,
            riddenAt: new DateTimeImmutable($at, new DateTimeZone('UTC')),
            durationS: $durationS,
        );
    }

    /** @param list<MatchedSegment> $segments */
    private function service(array $segments): GameIngestionService
    {
        return new GameIngestionService(
            new FakeEdgeMatcher($segments),
            $this->repo,
            new EdgeRecalculator($this->repo, $this->config),
            $this->config,
            $this->pdo,
        );
    }

    private function now(string $iso = '2026-06-20T08:00:00Z'): DateTimeImmutable
    {
        return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }

    private function edgeId(): int
    {
        $edges = $this->repo->edgesInBbox(9.6, 47.1, 9.7, 47.2, null, 100);
        return (int)$edges[0]['id'];
    }

    /** AK2: getimte Live-Fahrt erzeugt genau einen Effort mit korrekter Dauer. */
    public function testLiveRideRecordsOneEffortWithDerivedDuration(): void
    {
        $u = $this->createUser('armin');
        $res = $this->service([$this->segment(lengthM: 500.0, avgSpeedKmh: 18.0)])
            ->ingest(1, $u, $this->parsedRoute(), true, $this->now());

        $this->assertSame(1, $res['efforts_new']);
        $rows = $this->repo->bestEffortsForEdge($this->edgeId(), null);
        $this->assertCount(1, $rows);
        // duration = 500 / (18/3.6) = 100 s
        $this->assertEqualsWithDelta(100.0, $rows[0]['duration_s'], 0.01);
        $this->assertEqualsWithDelta(18.0, $rows[0]['avg_speed_kmh'], 0.01);
    }

    /** AK2: explizite durationS aus dem Matcher hat Vorrang vor der Ableitung. */
    public function testExplicitDurationWins(): void
    {
        $u = $this->createUser('armin');
        $this->service([$this->segment(lengthM: 500.0, avgSpeedKmh: 18.0, durationS: 73.5)])
            ->ingest(1, $u, $this->parsedRoute(), true, $this->now());
        $rows = $this->repo->bestEffortsForEdge($this->edgeId(), null);
        $this->assertEqualsWithDelta(73.5, $rows[0]['duration_s'], 0.01);
    }

    /** AK3: zweite, schnellere Fahrt am selben Tag → zweiter Effort trotz Day-Cap. */
    public function testSecondFasterRideSameDayCreatesSecondEffort(): void
    {
        $u = $this->createUser('armin');
        $svc1 = $this->service([$this->segment(avgSpeedKmh: 18.0, at: '2026-06-20 08:00:00')]);
        $first = $svc1->ingest(1, $u, $this->parsedRoute(), true, $this->now());
        $svc2 = $this->service([$this->segment(avgSpeedKmh: 24.0, at: '2026-06-20 10:00:00')]);
        $second = $svc2->ingest(1, $u, $this->parsedRoute(), true, $this->now('2026-06-20T11:00:00Z'));

        $this->assertSame(1, $first['efforts_new']);
        $this->assertSame(1, $second['efforts_new']);
        $this->assertSame(0, $second['passes_new']);
        $this->assertSame(1, $second['skipped_day_cap']);

        // Bestzeit = die schnellere (24 km/h → 75 s).
        $rows = $this->repo->bestEffortsForEdge($this->edgeId(), null);
        $this->assertCount(1, $rows, 'eine Zeile pro Fahrer');
        $this->assertEqualsWithDelta(75.0, $rows[0]['duration_s'], 0.01);
    }

    /** AK4: kurze / unplausible / timing-lose Segmente erzeugen keinen Effort. */
    public function testEffortGatesSkipShortImplausibleAndNoTiming(): void
    {
        $short = $this->createUser('shorty');
        $r1 = $this->service([$this->segment(lengthM: 120.0, avgSpeedKmh: 18.0)])
            ->ingest(1, $short, $this->parsedRoute(), true, $this->now());
        $this->assertSame(0, $r1['efforts_new']);
        $this->assertSame(1, $r1['skipped_effort_short']);

        $fast = $this->createUser('rocket');
        $r2 = $this->service([$this->segment(lengthM: 500.0, avgSpeedKmh: 120.0)])
            ->ingest(2, $fast, $this->parsedRoute(), true, $this->now());
        $this->assertSame(0, $r2['efforts_new']);
        $this->assertSame(1, $r2['skipped_effort_implausible']);

        // Trusted Import ohne Timing (avgSpeed null, keine Motion) via Bypass.
        $imp = $this->createUser('importer');
        $seg = $this->segment(lengthM: 500.0, avgSpeedKmh: null, hasMotion: false);
        $r3 = $this->service([$seg])->ingest(3, $imp, $this->parsedRoute(), false, $this->now(), null, true);
        $this->assertSame(1, $r3['passes_new'], 'Besitz-Pass entsteht trotzdem');
        $this->assertSame(0, $r3['efforts_new']);
        $this->assertSame(1, $r3['skipped_effort_no_timing']);
    }

    /** AK5/AK6: Leaderboard aufsteigend, eine Zeile/Fahrer; anonym vs. Bearer. */
    public function testLeaderboardOrderingAndViewerFlags(): void
    {
        $a = $this->createUser('alpha');
        $b = $this->createUser('bravo');
        // A schneller (20 km/h → 90 s), B langsamer (18 km/h → 100 s).
        $this->service([$this->segment(avgSpeedKmh: 20.0)])->ingest(1, $a, $this->parsedRoute(), true, $this->now());
        $this->service([$this->segment(avgSpeedKmh: 18.0)])->ingest(2, $b, $this->parsedRoute(), true, $this->now());
        $edge = $this->edgeId();

        $anon = $this->speed->leaderboard($edge, 'world', 'all', null, $this->now());
        $this->assertNotNull($anon);
        $this->assertSame('alpha', $anon['entries'][0]['handle']);
        $this->assertSame(1, $anon['entries'][0]['rank']);
        $this->assertSame('bravo', $anon['entries'][1]['handle']);
        $this->assertFalse($anon['entries'][0]['is_me']);
        $this->assertNull($anon['me']);
        $this->assertSame($edge, $anon['segment']['edge_id']);

        $mine = $this->speed->leaderboard($edge, 'world', 'all', $a, $this->now());
        $this->assertNotNull($mine);
        $this->assertTrue($mine['entries'][0]['is_me']);
        $this->assertSame(1, $mine['me']['rank']);
        $this->assertEqualsWithDelta(90.0, $mine['me']['duration_s'], 0.01);
    }

    /** AK7: friends-Scope grenzt auf gefolgte Fahrer + self ein; window filtert. */
    public function testFriendsScopeAndWindowFilter(): void
    {
        $viewer = $this->createUser('viewer');
        $friend = $this->createUser('friend');
        $stranger = $this->createUser('stranger');
        $this->seedFollow($viewer, $friend);

        // friend recent, stranger 30 Tage alt.
        $this->service([$this->segment(avgSpeedKmh: 18.0, at: '2026-06-18 09:00:00')])
            ->ingest(1, $friend, $this->parsedRoute(), true, $this->now());
        $this->service([$this->segment(avgSpeedKmh: 20.0, at: '2026-05-21 09:00:00')])
            ->ingest(2, $stranger, $this->parsedRoute(), true, $this->now());
        $edge = $this->edgeId();

        // friends: nur friend (stranger nicht gefolgt), viewer hat selbst keinen Effort.
        $friends = $this->speed->leaderboard($edge, 'friends', 'all', $viewer, $this->now());
        $this->assertNotNull($friends);
        $this->assertSame(['friend'], array_column($friends['entries'], 'handle'));

        // window=week: stranger (30 T alt) fällt raus, friend bleibt.
        $week = $this->speed->leaderboard($edge, 'world', 'week', null, $this->now());
        $this->assertSame(['friend'], array_column($week['entries'], 'handle'));

        // window=all: beide.
        $all = $this->speed->leaderboard($edge, 'world', 'all', null, $this->now());
        $this->assertEqualsCanonicalizing(['friend', 'stranger'], array_column($all['entries'], 'handle'));
    }

    /** AK8: unbekannte Kante → null; existierende ohne Efforts → leere entries. */
    public function testUnknownEdgeAndEmptyLeaderboard(): void
    {
        $this->assertNull($this->speed->leaderboard(999999, 'world', 'all', null, $this->now()));

        // Kante ohne (qualifizierte) Efforts: kurze Befahrung erzeugt nur die Kante.
        $u = $this->createUser('armin');
        $this->service([$this->segment(lengthM: 120.0, avgSpeedKmh: 18.0)])
            ->ingest(1, $u, $this->parsedRoute(), true, $this->now());
        $res = $this->speed->leaderboard($this->edgeId(), 'world', 'all', null, $this->now());
        $this->assertNotNull($res);
        $this->assertSame([], $res['entries']);
        $this->assertNull($res['me']);
    }

    /** AK9: /me/segments mit Rang/Teilnehmer + Pagination. */
    public function testMySegmentsRankAndPagination(): void
    {
        $me = $this->createUser('me_rider');
        $rival = $this->createUser('rival');
        // me langsamer (18 → 100 s), rival schneller (24 → 75 s) auf derselben Kante.
        $this->service([$this->segment(avgSpeedKmh: 18.0)])->ingest(1, $me, $this->parsedRoute(), true, $this->now());
        $this->service([$this->segment(avgSpeedKmh: 24.0)])->ingest(2, $rival, $this->parsedRoute(), true, $this->now());

        $res = $this->speed->mySegments($me, 'all', 50, 0, $this->now());
        $this->assertCount(1, $res['segments']);
        $seg = $res['segments'][0];
        $this->assertSame(2, $seg['total_riders']);
        $this->assertSame(2, $seg['rank'], 'rival ist schneller → me ist Rang 2');
        $this->assertEqualsWithDelta(100.0, $seg['best_duration_s'], 0.01);
        $this->assertSame(1, $res['pagination']['total']);
        $this->assertFalse($res['pagination']['has_more']);
    }

    /** AK10: Tie-Break deterministisch (gleiche Dauer → frühere achieved_at). */
    public function testDeterministicTieBreak(): void
    {
        $early = $this->createUser('early');
        $late  = $this->createUser('late');
        // Gleiche Dauer (gleiches Tempo/Länge), aber unterschiedliche Zeitpunkte.
        $this->service([$this->segment(avgSpeedKmh: 18.0, at: '2026-06-10 09:00:00')])
            ->ingest(1, $early, $this->parsedRoute(), true, $this->now());
        $this->service([$this->segment(avgSpeedKmh: 18.0, at: '2026-06-18 09:00:00')])
            ->ingest(2, $late, $this->parsedRoute(), true, $this->now());

        $res = $this->speed->leaderboard($this->edgeId(), 'world', 'all', null, $this->now());
        $this->assertSame('early', $res['entries'][0]['handle'], 'frühere achieved_at gewinnt den Tie');
        $this->assertSame('late', $res['entries'][1]['handle']);
    }
}
