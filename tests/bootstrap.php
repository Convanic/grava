<?php
declare(strict_types=1);

/**
 * PHPUnit-Bootstrap.
 *
 * Richtet eine *getrennte* Test-Datenbank ein, damit Integrationstests
 * niemals die Entwicklungs-/Produktiv-DB berühren. Die Test-Env-Werte
 * werden hier — VOR {@see App\Config\Config::boot()} — gesetzt, weil
 * Dotenv im Immutable-Modus (`safeLoad`) bereits gesetzte Variablen
 * nicht überschreibt. So gewinnt unser DB_NAME-Override gegenüber .env.
 */

use App\Config\Config;
use App\Database\Db;
use App\Database\Migrator;

require __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);

// --- Test-Umgebung erzwingen (deterministisch, nicht auf PHPUnit-<env>
//     Reihenfolge angewiesen) ---------------------------------------------
$tmpStorage = sys_get_temp_dir() . '/ge_test_storage';
@mkdir($tmpStorage . '/routes', 0777, true);
@mkdir($tmpStorage . '/avatars', 0777, true);
@mkdir($tmpStorage . '/crew-logos', 0777, true);

$forced = [
    'APP_ENV'                => 'testing',
    'DB_NAME'                => getenv('DB_NAME') ?: 'gravelexplorer_test',
    'STRAVA_FAKE'            => '1',
    'MAIL_HOST'              => '',
    'STORAGE_ROUTES_DIR'     => $tmpStorage . '/routes',
    'STORAGE_AVATARS_DIR'    => $tmpStorage . '/avatars',
    'STORAGE_CREW_LOGOS_DIR' => $tmpStorage . '/crew-logos',
];
foreach ($forced as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

Config::boot($basePath);
$cfg = Config::instance();

// --- Schutzschranke: NIE eine DB ohne _test-Suffix migrieren/truncaten ---
$dbName = (string)$cfg->get('DB_NAME', '');
if (!str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "ABBRUCH: Test-DB '{$dbName}' hat kein _test-Suffix. Tests verweigert.\n");
    exit(1);
}

// --- Test-DB anlegen (Verbindung ohne dbname) ----------------------------
$socket = (string)$cfg->get('DB_SOCKET', '');
$user   = (string)$cfg->get('DB_USER', '');
$pass   = (string)$cfg->get('DB_PASS', '');
$dsnNoDb = $socket !== ''
    ? "mysql:unix_socket={$socket};charset=utf8mb4"
    : 'mysql:host=' . (string)$cfg->get('DB_HOST', '127.0.0.1')
        . ';port=' . (string)$cfg->int('DB_PORT', 3306) . ';charset=utf8mb4';

// DB-Setup ist „best effort": gelingt es nicht (z. B. keine MySQL-
// Verbindung in einer reinen Unit-Test-Umgebung), laufen die Unit-Tests
// trotzdem; Integrationstests skippen sich dann selbst (siehe
// IntegrationTestCase). So bleibt `phpunit --testsuite unit` ohne DB
// nutzbar.
$dbReady = false;
try {
    $admin = new PDO($dsnNoDb, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $admin->exec(
        "CREATE DATABASE IF NOT EXISTS `{$dbName}` "
        . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $admin = null;

    Db::reset();
    (new Migrator($basePath . '/migrations'))->migrate();
    $dbReady = true;
} catch (\Throwable $e) {
    fwrite(STDERR, "WARNUNG: Test-DB nicht verfügbar — Integrationstests werden übersprungen. ({$e->getMessage()})\n");
}

define('GE_TEST_DB_READY', $dbReady);
