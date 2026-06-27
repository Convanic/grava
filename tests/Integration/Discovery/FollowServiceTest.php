<?php
declare(strict_types=1);

namespace Tests\Integration\Discovery;

use App\Discovery\FollowService;
use App\Discovery\SocialException;
use App\Engagement\NotificationService;
use PDO;
use Tests\IntegrationTestCase;

/**
 * Schreib-Pfad von Follow/Unfollow (FollowService::follow + unfollow).
 *
 * Die vorhandenen Tests decken nur die Listen-Endpoints ab; hier geht es
 * um die eigentliche Schreib-Aktion inkl. Notification-Seam (wie in
 * public/index.php verdrahtet: `new FollowService($notifServ)`).
 */
final class FollowServiceTest extends IntegrationTestCase
{
    private function rowExists(int $follower, int $followee): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM follows WHERE follower_id = ? AND followee_id = ?'
        );
        $stmt->execute([$follower, $followee]);
        return (bool)$stmt->fetchColumn();
    }

    public function testFollowCreatesRelationAndIsIdempotent(): void
    {
        $svc = new FollowService(new NotificationService());
        $a = $this->createUser('alpha');
        $b = $this->createUser('beta');

        $this->assertTrue($svc->follow($a, $b), 'Erster Follow soll true (neu) liefern');
        $this->assertTrue($this->rowExists($a, $b));

        $this->assertFalse($svc->follow($a, $b), 'Zweiter Follow soll false (idempotent) liefern');
    }

    public function testUnfollowRemovesRelationAndIsSafeWhenMissing(): void
    {
        $svc = new FollowService(new NotificationService());
        $a = $this->createUser('gamma');
        $b = $this->createUser('delta');

        $svc->follow($a, $b);
        $svc->unfollow($a, $b);
        $this->assertFalse($this->rowExists($a, $b));

        // Nochmaliges Unfollow ohne Beziehung darf nicht crashen.
        $svc->unfollow($a, $b);
        $this->assertFalse($this->rowExists($a, $b));
    }

    public function testSelfFollowThrows422(): void
    {
        $svc = new FollowService(new NotificationService());
        $a = $this->createUser('solo');

        try {
            $svc->follow($a, $a);
            $this->fail('Self-Follow muss eine SocialException werfen');
        } catch (SocialException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * Regression: Der Follow selbst muss gelingen, auch wenn der
     * Notification-/Push-Pfad scheitert. Laut NotificationService-Doc
     * ist notify() „best effort" und der Aufrufer (FollowService) soll
     * jeden Notification-Fehler schlucken. Wir simulieren einen
     * Schema-Drift, bei dem der Typ 'follow' nicht (mehr) im ENUM ist —
     * der Notification-INSERT wirft dann einen Nicht-1062/Nicht-1146-
     * Fehler, der den Follow NICHT abbrechen darf.
     */
    public function testFollowSucceedsEvenIfNotificationWriteFails(): void
    {
        $svc = new FollowService(new NotificationService());
        $a = $this->createUser('robust_a');
        $b = $this->createUser('robust_b');

        $originalType = (string)$this->pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'notifications'
                AND column_name = 'type'"
        )->fetchColumn();

        // ENUM so verengen, dass 'follow' ungültig wird → INSERT scheitert
        // (im strikten SQL-Modus mit einem Nicht-1062/Nicht-1146-Fehler).
        $this->pdo->exec("ALTER TABLE notifications MODIFY `type` ENUM('like','comment') NOT NULL");
        try {
            $result = $svc->follow($a, $b);
            $this->assertTrue($result, 'Follow soll true liefern, auch wenn Notification scheitert');
            $this->assertTrue(
                $this->rowExists($a, $b),
                'Follow-Beziehung muss trotz fehlgeschlagener Notification bestehen'
            );
        } finally {
            $this->pdo->exec("ALTER TABLE notifications MODIFY `type` {$originalType} NOT NULL");
        }
    }

    public function testFollowWritesNotificationRow(): void
    {
        $svc = new FollowService(new NotificationService());
        $a = $this->createUser('actor_x');
        $b = $this->createUser('target_y');

        $svc->follow($a, $b);

        $stmt = $this->pdo->prepare(
            'SELECT type, subject_type, subject_id, actor_id
               FROM notifications WHERE user_id = ?'
        );
        $stmt->execute([$b]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($row, 'Follow soll eine Notification an den Gefolgten anlegen');
        $this->assertSame('follow', $row['type']);
        $this->assertSame('user', $row['subject_type']);
        $this->assertSame($a, (int)$row['actor_id']);
    }
}
