<?php
declare(strict_types=1);

/*
 * Lokaler Seeder für das Game-Admin-Dashboard (NUR Entwicklung).
 *
 * Befüllt nodes/edges/passes + ingest-log/audit/user-flags mit realistischen
 * Testdaten und lässt anschliessend die echte Recompute-Logik
 * (game:recompute) Besitz/Wert/Frische ableiten.
 *
 * Idempotent: löscht zuvor alle game_*-Daten und frühere Seed-User
 * (E-Mail endet auf @seed.grava.test). Reale Accounts (inkl. Admin) bleiben.
 *
 * Aufruf:  php scripts/seed_game_dashboard.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Auth\PasswordService;
use App\Config\Config;
use App\Database\Db;
use App\Support\Uuid;

Config::boot(dirname(__DIR__));

const SEED_DOMAIN = '@seed.grava.test';
const EDGE_COUNT  = 260;     // > mod_max_passes_per_day (200) für Moderation-Flag
const HIGH_VOLUME = 210;     // Pässe des "speeddemon" heute (löst High-Volume aus)

mt_srand(1337);

$pdo = Db::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$fmtDt = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s.v');
$fmtD  = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d');

echo "== Game-Dashboard Seeder ==\n";

/* ---------------------------------------------------------------------------
 * 1) Teardown: vorhandene Spiel-Daten + frühere Seed-User entfernen
 * ------------------------------------------------------------------------- */
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ([
    'game_edge_pass', 'game_ingest_log', 'game_audit', 'game_user_flag',
    'game_edge', 'game_claimant', 'game_node',
] as $t) {
    $pdo->exec("DELETE FROM {$t}");
}
$delUsers = $pdo->prepare('DELETE FROM users WHERE email LIKE ?');
$delUsers->execute(['%' . SEED_DOMAIN]);
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
echo "Teardown: Spiel-Tabellen geleert, alte Seed-User entfernt.\n";

/* ---------------------------------------------------------------------------
 * 2) Rider-User + Claimants
 * ------------------------------------------------------------------------- */
$passwords = new PasswordService();
$riderHandles = [
    'trailblazer', 'gravelqueen', 'mudlover', 'alpenfox',
    'kiesbiker', 'waldgeist', 'speeddemon', 'sonntagsfahrer',
];

$insUser = $pdo->prepare(
    'INSERT INTO users (public_id, email, email_verified_at, password_hash, display_name, public_handle, status, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, "active", ?, ?)'
);
$insClaimant = $pdo->prepare(
    'INSERT INTO game_claimant (type, user_id, created_at) VALUES ("rider", ?, ?)'
);

$now = $fmtDt($today);
$riders = []; // handle => ['user_id'=>, 'claimant_id'=>]

// Admin (falls vorhanden) ebenfalls als Rider aufnehmen, damit er im Leaderboard auftaucht.
$adminRow = $pdo->query("SELECT id, public_handle FROM users WHERE email = 'grava@benx.de' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($adminRow) {
    $adminId = (int)$adminRow['id'];
    if ($adminRow['public_handle'] === null) {
        $pdo->prepare('UPDATE users SET public_handle = ? WHERE id = ?')->execute(['grava_admin', $adminId]);
    }
    $insClaimant->execute([$adminId, $now]);
    $riders['grava_admin'] = ['user_id' => $adminId, 'claimant_id' => (int)$pdo->lastInsertId()];
}

$hash = $passwords->hash('seedRider!2026');
foreach ($riderHandles as $h) {
    $insUser->execute([
        Uuid::v4(), $h . SEED_DOMAIN, $now, $hash,
        ucfirst($h), $h, $now, $now,
    ]);
    $uid = (int)$pdo->lastInsertId();
    $insClaimant->execute([$uid, $now]);
    $riders[$h] = ['user_id' => $uid, 'claimant_id' => (int)$pdo->lastInsertId()];
}
echo 'Rider angelegt: ' . count($riders) . " (inkl. Admin).\n";

// Pool für "normale" Pässe (ohne speeddemon, der macht den High-Volume-Tag).
$normalPool = array_values(array_filter(
    array_keys($riders),
    static fn(string $h) => $h !== 'speeddemon'
));

/* ---------------------------------------------------------------------------
 * 3) Nodes + Edges (verbundene Polylinie nahe Stuttgart)
 * ------------------------------------------------------------------------- */
$lat0 = 48.7758;
$lon0 = 9.1829;
$haversine = static function (float $la1, float $lo1, float $la2, float $lo2): float {
    $r = 6371000.0;
    $dLa = deg2rad($la2 - $la1);
    $dLo = deg2rad($lo2 - $lo1);
    $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
};

$insNode = $pdo->prepare(
    'INSERT INTO game_node (osm_node_id, lat, lon, created_at) VALUES (?, ?, ?, ?)'
);
$nodeIds = [];
$coords  = [];
for ($i = 0; $i <= EDGE_COUNT; $i++) {
    $lat = $lat0 + ($i * 0.0006) + 0.0004 * sin($i / 5.0);
    $lon = $lon0 + ($i * 0.0003) + 0.0004 * cos($i / 4.0);
    $insNode->execute([900000000 + $i, $lat, $lon, $now]);
    $nodeIds[$i] = (int)$pdo->lastInsertId();
    $coords[$i]  = [$lon, $lat];
}

$surfaces = ['gravel', 'paved', 'dirt', 'unpaved', 'compacted'];
$insEdge = $pdo->prepare(
    'INSERT INTO game_edge
        (way_id, node_a_id, node_b_id, length_m, geom_geojson, surface_character,
         min_lat, min_lon, max_lat, max_lon, distinct_riders_total, value_cached, freshness_cached, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
);
$edgeIds = [];
for ($e = 0; $e < EDGE_COUNT; $e++) {
    [$lonA, $latA] = $coords[$e];
    [$lonB, $latB] = $coords[$e + 1];
    $len  = max(20.0, $haversine($latA, $lonA, $latB, $lonB));
    $geom = json_encode(['type' => 'LineString', 'coordinates' => [[$lonA, $latA], [$lonB, $latB]]]);
    $insEdge->execute([
        7000 + intdiv($e, 20),         // way_id, ~20 Kanten pro Weg
        $nodeIds[$e], $nodeIds[$e + 1],
        round($len, 1),
        $geom,
        $surfaces[$e % count($surfaces)],
        min($latA, $latB), min($lonA, $lonB), max($latA, $latB), max($lonA, $lonB),
        $now,
    ]);
    $edgeIds[$e] = (int)$pdo->lastInsertId();
}
echo 'Nodes: ' . count($nodeIds) . ', Edges: ' . count($edgeIds) . ".\n";

/* ---------------------------------------------------------------------------
 * 4) Pässe (Quelle der Wahrheit) — in einer Transaktion
 * ------------------------------------------------------------------------- */
$insPass = $pdo->prepare(
    'INSERT INTO game_edge_pass
        (edge_id, claimant_id, user_id, route_id, ridden_on, ridden_at, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);

$pdo->beginTransaction();
$routeSeq = 5000;
$passCount = 0;
$todayPassEdges = []; // route_ids für Ingest-Log-Querverweis

// 4a) Normale Pässe: pro Kante 1–4 verschiedene Rider an verschiedenen Tagen
foreach ($edgeIds as $e => $edgeId) {
    $count = 1 + ($e * 7 + 3) % 4; // 1..4 deterministisch
    $pool  = $normalPool;
    shuffle($pool);
    $chosen = array_slice($pool, 0, $count);
    $usedDays = [];
    foreach ($chosen as $idx => $h) {
        // erster Rider gelegentlich "heute" (für passes_24h + Frische)
        if ($idx === 0 && $e % 6 === 0) {
            $dayOffset = 0;
        } else {
            do {
                $dayOffset = mt_rand(0, 89);
            } while (isset($usedDays[$dayOffset]));
        }
        $usedDays[$dayOffset] = true;
        $riddenAt = $today->modify("-{$dayOffset} days")
            ->setTime(mt_rand(6, 19), mt_rand(0, 59), mt_rand(0, 59));
        $insPass->execute([
            $edgeId,
            $riders[$h]['claimant_id'],
            $riders[$h]['user_id'],
            $routeSeq++,
            $fmtD($riddenAt),
            $fmtDt($riddenAt),
            $fmtDt($riddenAt),
        ]);
        $passCount++;
    }
}

// 4b) High-Volume-Rider: speeddemon fährt heute HIGH_VOLUME Kanten (Moderation)
$speed = $riders['speeddemon'];
$bulkRoute = $routeSeq++;
for ($e = 0; $e < HIGH_VOLUME; $e++) {
    $riddenAt = $today->setTime(8, 0, 0)->modify('+' . $e . ' seconds');
    $insPass->execute([
        $edgeIds[$e], $speed['claimant_id'], $speed['user_id'], $bulkRoute,
        $fmtD($riddenAt), $fmtDt($riddenAt), $fmtDt($riddenAt),
    ]);
    $passCount++;
}
$pdo->commit();
echo "Pässe eingespielt: {$passCount} (davon {$bulkRoute}=High-Volume-Route von speeddemon).\n";

/* ---------------------------------------------------------------------------
 * 5) Ein paar Pässe soft-invalidieren (zeigt Ausschluss + Inspector-Historie)
 * ------------------------------------------------------------------------- */
$adminIdForAudit = $adminRow ? (int)$adminRow['id'] : ($riders[array_key_first($riders)]['user_id']);
$pdo->prepare(
    'UPDATE game_edge_pass
        SET invalidated_at = ?, invalidated_by = ?, invalid_reason = ?
      WHERE id IN (SELECT id FROM (SELECT id FROM game_edge_pass ORDER BY id ASC LIMIT 5) x)'
)->execute([$now, $adminIdForAudit, 'Seed: Beispiel-Invalidierung (GPS-Drift)']);
echo "5 Pässe als Beispiel invalidiert.\n";

/* ---------------------------------------------------------------------------
 * 6) Ingest-Log (Monitor + Health-Match-Rate)
 * ------------------------------------------------------------------------- */
$insIngest = $pdo->prepare(
    'INSERT INTO game_ingest_log
        (route_id, user_id, status, matched_edges, new_passes, skipped_json, valhalla_error, duration_ms, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$riderUserIds = array_map(static fn(array $r) => $r['user_id'], array_values($riders));
$valhallaErrors = [
    'Valhalla 400: No suitable edges near location',
    'Valhalla timeout after 30s',
    'trace_attributes: low-confidence match (0.21)',
];
for ($i = 0; $i < 32; $i++) {
    $roll = mt_rand(1, 100);
    $status = $roll <= 80 ? 'ok' : ($roll <= 90 ? 'pending' : 'failed');
    $matched = $status === 'failed' ? 0 : mt_rand(3, 45);
    $newPasses = $status === 'ok' ? mt_rand(0, $matched) : 0;
    $skipped = ($status === 'ok' && mt_rand(0, 1))
        ? json_encode(['too_slow' => mt_rand(0, 3), 'low_acc' => mt_rand(0, 2)])
        : null;
    $err = $status === 'failed' ? $valhallaErrors[array_rand($valhallaErrors)] : null;
    $createdAt = $today->modify('-' . mt_rand(0, 6) . ' days')
        ->setTime(mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59));
    $insIngest->execute([
        6000 + $i,
        $riderUserIds[array_rand($riderUserIds)],
        $status, $matched, $newPasses, $skipped, $err,
        $status === 'failed' ? mt_rand(50, 30000) : mt_rand(60, 1500),
        $fmtDt($createdAt),
    ]);
}
echo "Ingest-Log: 32 Einträge (ok/pending/failed gemischt).\n";

/* ---------------------------------------------------------------------------
 * 7) User-Flag (Ban) + Audit-Beispiele
 * ------------------------------------------------------------------------- */
$bannedId = $riders['sonntagsfahrer']['user_id'];
$pdo->prepare(
    'INSERT INTO game_user_flag (user_id, banned, reason, updated_at) VALUES (?, 1, ?, ?)'
)->execute([$bannedId, 'Seed: wiederholtes Auto-Tracking erkannt', $now]);

$insAudit = $pdo->prepare(
    'INSERT INTO game_audit (admin_user_id, action, target, detail_json, created_at) VALUES (?, ?, ?, ?, ?)'
);
$auditRows = [
    ['config.update', 'popularity_c', json_encode(['old' => '30', 'new' => '28'])],
    ['pass.invalidate', 'pass:bulk', json_encode(['count' => 5, 'reason' => 'GPS-Drift'])],
    ['user.ban', 'user:' . $bannedId, json_encode(['reason' => 'Auto-Tracking'])],
    ['config.update', 'mod_max_passes_per_day', json_encode(['old' => '200', 'new' => '200'])],
];
foreach ($auditRows as $k => $a) {
    $insAudit->execute([
        $adminIdForAudit, $a[0], $a[1], $a[2],
        $fmtDt($today->modify('-' . ($k * 3) . ' hours')),
    ]);
}
echo "User-Flag (1 Ban) + Audit (" . count($auditRows) . " Einträge) angelegt.\n";

echo "\nSeed fertig. Jetzt Recompute ausführen:\n  php public/index.php game:recompute\n";
