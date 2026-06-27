<?php
declare(strict_types=1);

namespace Tests\Integration\Game\Crew;

use App\Config\Config;
use App\Game\Crew\CrewLogoException;
use App\Game\Crew\CrewLogoService;
use App\Game\Crew\CrewRepository;
use Tests\IntegrationTestCase;

/** Akzeptanzkriterien aus backend/GAME_CREW_LOGO_BACKEND.md §5. */
final class CrewLogoServiceTest extends IntegrationTestCase
{
    private CrewRepository $crews;
    private CrewLogoService $logos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crews = new CrewRepository($this->pdo);
        $this->logos = new CrewLogoService($this->crews, Config::instance());
        // saubere Basis: evtl. Reste aus vorherigen Läufen entfernen
        foreach ((array)@glob($this->logos->baseDir() . '/*.jpg') as $f) {
            @unlink($f);
        }
    }

    /** AK1: Captain lädt JPEG hoch → ok + logo_path; Datei wird ausgeliefert. */
    public function testCaptainUploadStoresAndServes(): void
    {
        [$crewId, $slug, $captain] = $this->makeCrew('logo-crew', 'JOINLOG1');

        $res = $this->logos->store($slug, $captain, $this->upload($this->jpeg(800, 600)));

        $this->assertStringContainsString('/storage/crew-logos/' . $crewId . '.jpg', $res['logo_path']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $res['logo_updated_at']);

        $served = $this->logos->resolveForSlug($slug);
        $this->assertNotNull($served);
        $this->assertSame('image/jpeg', $served['mime']);
        $this->assertTrue(is_file($served['path']));

        // logo_updated_at landet im Crew-JSON-Quellfeld
        $crew = $this->crews->crewById($crewId);
        $this->assertNotNull($crew['logo_updated_at']);
    }

    /** AK2: Nicht-Captain (gewöhnliches Mitglied) ⇒ 403. */
    public function testNonCaptainForbidden(): void
    {
        [$crewId, $slug] = $this->makeCrew('crew-403', 'JOIN4031');
        $member = $this->createUser('member403');
        $this->crews->addMember($member, $crewId, 'member');

        $this->expectStatus(403, fn () => $this->logos->store($slug, $member, $this->upload($this->jpeg())));
    }

    /** AK2: völlig Fremder (kein Mitglied) ⇒ 403. */
    public function testStrangerForbidden(): void
    {
        [, $slug] = $this->makeCrew('crew-strange', 'JOINSTR1');
        $stranger = $this->createUser('stranger');

        $this->expectStatus(403, fn () => $this->logos->store($slug, $stranger, $this->upload($this->jpeg())));
    }

    /** AK2: unbekannter Slug ⇒ 404. */
    public function testUnknownSlugNotFound(): void
    {
        $user = $this->createUser('nobody');
        $this->expectStatus(404, fn () => $this->logos->store('does-not-exist', $user, $this->upload($this->jpeg())));
    }

    /** AK3: kein Logo ⇒ Serving null (Controller → 404); logo_updated_at == null. */
    public function testNoLogoResolvesNull(): void
    {
        [$crewId, $slug] = $this->makeCrew('crew-empty', 'JOINEMP1');
        $this->assertNull($this->logos->resolveForSlug($slug));
        $crew = $this->crews->crewById($crewId);
        $this->assertNull($crew['logo_updated_at']);
    }

    /** AK4: Replace bustet den Cache (logo_updated_at ändert sich). */
    public function testReplaceUpdatesTimestamp(): void
    {
        [$crewId, $slug, $captain] = $this->makeCrew('crew-replace', 'JOINREP1');

        $this->logos->store($slug, $captain, $this->upload($this->jpeg()));
        $first = (string)$this->crews->crewById($crewId)['logo_updated_at'];

        usleep(5000); // sicherstellen, dass NOW() (ms-genau) sich ändert
        $this->logos->store($slug, $captain, $this->upload($this->jpeg()));
        $second = (string)$this->crews->crewById($crewId)['logo_updated_at'];

        $this->assertNotSame($first, $second);
    }

    /** AK5: Delete ⇒ Datei + DB-Spalten weg; Serving danach null. */
    public function testDeleteRemovesFileAndColumns(): void
    {
        [$crewId, $slug, $captain] = $this->makeCrew('crew-del', 'JOINDEL1');
        $this->logos->store($slug, $captain, $this->upload($this->jpeg()));
        $served = $this->logos->resolveForSlug($slug);
        $this->assertNotNull($served);
        $absPath = $served['path'];

        $this->logos->delete($slug, $captain);

        $this->assertFalse(is_file($absPath));
        $this->assertNull($this->logos->resolveForSlug($slug));
        $this->assertNull($this->crews->crewById($crewId)['logo_updated_at']);
    }

    /** AK6: falscher MIME (kein Bild) ⇒ 415. */
    public function testNonImageUnsupportedType(): void
    {
        [, $slug, $captain] = $this->makeCrew('crew-415', 'JOIN4151');
        $this->expectStatus(415, fn () => $this->logos->store($slug, $captain, $this->upload('das ist kein bild')));
    }

    /** AK6: zu groß ⇒ 413. */
    public function testTooLarge(): void
    {
        [, $slug, $captain] = $this->makeCrew('crew-413', 'JOIN4131');
        $blob = str_repeat('x', CrewLogoService::MAX_BYTES + 1);
        $this->expectStatus(413, fn () => $this->logos->store($slug, $captain, $this->upload($blob)));
    }

    /** AK6: Ergebnis ist quadratisch und ≤ 512×512. */
    public function testResultIsSquareAndCapped(): void
    {
        [, $slug, $captain] = $this->makeCrew('crew-dim', 'JOINDIM1');
        $this->logos->store($slug, $captain, $this->upload($this->jpeg(1200, 800)));

        $served = $this->logos->resolveForSlug($slug);
        $size = getimagesize($served['path']);
        $this->assertSame(IMAGETYPE_JPEG, $size[2]);
        $this->assertSame($size[0], $size[1], 'Logo muss quadratisch sein.');
        $this->assertLessThanOrEqual(CrewLogoService::MAX_DIM, $size[0]);
    }

    // ----------------------------------------------------------------
    // Helfer
    // ----------------------------------------------------------------

    /**
     * Legt eine Crew mit Captain an und liefert [crewId, slug, captainUserId].
     *
     * @return array{0:int,1:string,2:int}
     */
    private function makeCrew(string $slug, string $joinCode): array
    {
        $captain = $this->createUser('cap_' . $slug);
        $this->pdo->prepare('INSERT INTO game_claimant (type, user_id) VALUES ("group", NULL)')->execute();
        $claimant = (int)$this->pdo->lastInsertId();
        $crewId = $this->crews->createCrew($claimant, ucfirst($slug), $slug, $captain, $joinCode);
        $this->crews->addMember($captain, $crewId, 'captain');
        return [$crewId, $slug, $captain];
    }

    /** Erzeugt ein in-memory JPEG der gewünschten Größe und liefert die Bytes. */
    private function jpeg(int $w = 640, int $h = 640): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 30, 120, 200));
        ob_start();
        imagejpeg($img, null, 85);
        $bytes = (string)ob_get_clean();
        imagedestroy($img);
        return $bytes;
    }

    /**
     * Schreibt $bytes in eine Temp-Datei und liefert ein Upload-Array wie
     * Request::file() es liefert (ohne is_uploaded_file-Check, der nur für
     * echte HTTP-Uploads greift).
     *
     * @return array{tmp_name:string, size:int, type:string, name:string}
     */
    private function upload(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ge_logo_');
        file_put_contents($tmp, $bytes);
        return ['tmp_name' => $tmp, 'size' => strlen($bytes), 'type' => 'image/jpeg', 'name' => 'logo.jpg'];
    }

    /** Erwartet, dass $fn eine CrewLogoException mit gegebenem HTTP-Status wirft. */
    private function expectStatus(int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Erwartete CrewLogoException mit Status {$status}, aber keine geworfen.");
        } catch (CrewLogoException $e) {
            $this->assertSame($status, $e->httpStatus);
        }
    }
}
