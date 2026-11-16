<?php
/*
 * TDC Maintenance Tracker – Dental Dashboard tiles
 *
 * GET /tdc/tiles/overdue   and   GET /tdc/tiles/due-week
 * (TDC-Office-Plan CONTRACT §5 plus the standalone-app rules agreed in TDC-Office-Plan#23).
 *
 * Only the Dashboard's server calls this, with  Authorization: Bearer <TILE_TOKEN>.
 * TILE_TOKEN lives in config.php on the server (never in GitHub). No token set = tiles off (404).
 * Tiles hold task names, frequencies, dates and counts. This app has no patient data.
 *
 * The page keeps a small snapshot in settings/tiles: for each active task, the day it turns
 * overdue if nobody logs it. This file only compares those dates with today.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const TILE_RATE = 60;           // requests per minute, all callers together
const TILE_TZ   = 'America/Los_Angeles';

function tile_out(int $code, array $body = []): void {
    http_response_code($code);
    // Log only the tile id and the status code (nothing about the caller or the token).
    error_log('tdc-tile ' . preg_replace('/[^a-z-]/', '', (string)($_GET['id'] ?? '')) . ' ' . $code);
    if ($body) echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$cfg = __DIR__ . '/config.php';
if (!is_file($cfg)) tile_out(404);
require $cfg;
$token = defined('TILE_TOKEN') ? (string)TILE_TOKEN : '';
if (strlen($token) < 32) tile_out(404);                       // tiles turned off
if ($_SERVER['REQUEST_METHOD'] !== 'GET') tile_out(405);

$id = (string)($_GET['id'] ?? '');
if (!in_array($id, ['overdue', 'due-week'], true)) tile_out(404);

/* ---------- database (same settings as api.php) ---------- */
try {
    if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_SQLITE_PATH);
        $mysql = false;
    } else {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 2]);
        $mysql = true;
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) { tile_out(503); }

/* ---------- rate limit (checked before the token, so guessing is slow too) ---------- */
try {
    $pdo->exec('CREATE TABLE IF NOT EXISTS tdc_tilerate (minute BIGINT NOT NULL PRIMARY KEY, hits INT NOT NULL)');
    $m = intdiv(time(), 60);
    $pdo->prepare('DELETE FROM tdc_tilerate WHERE minute < ?')->execute([$m - 5]);
    $pdo->prepare($mysql
        ? 'INSERT INTO tdc_tilerate (minute, hits) VALUES (?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1'
        : 'INSERT INTO tdc_tilerate (minute, hits) VALUES (?, 1) ON CONFLICT(minute) DO UPDATE SET hits = hits + 1')->execute([$m]);
    $st = $pdo->prepare('SELECT hits FROM tdc_tilerate WHERE minute = ?'); $st->execute([$m]);
    if ((int)($st->fetch()['hits'] ?? 0) > TILE_RATE) { header('Retry-After: 60'); tile_out(429); }
} catch (Throwable $e) { tile_out(503); }

/* ---------- token ---------- */
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $auth = (string)$v;
}
$given = preg_match('/^Bearer\s+(\S+)$/i', $auth, $mm) ? $mm[1] : '';
if ($given === '' || !hash_equals($token, $given)) tile_out(401);

/* ---------- the snapshot ---------- */
$snap = null;
try {
    $st = $pdo->prepare("SELECT data FROM tdc_docs WHERE col = 'settings' AND id = 'tiles'");
    $st->execute();
    $row = $st->fetch();
    if ($row) $snap = json_decode((string)$row['data'], true);
} catch (Throwable $e) { tile_out(503); }
$tasks = (is_array($snap) && is_array($snap['tasks'] ?? null)) ? $snap['tasks'] : [];

$tz    = new DateTimeZone(TILE_TZ);
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$week  = (new DateTimeImmutable('now', $tz))->modify('+6 days')->format('Y-m-d');
$base  = (defined('APP_URL') && preg_match('#^https://#', (string)APP_URL))
    ? rtrim((string)APP_URL, '/')
    : 'https://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));

$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
$fmt = function (string $d, bool $weekday) use ($tz): string {
    $x = DateTimeImmutable::createFromFormat('!Y-m-d', $d, $tz);
    return $x ? $x->format($weekday ? 'D' : 'M j') : $d;
};
$label = fn($t) => trim(mb_substr((string)($t['n'] ?? ''), 0, 80)) . ' · ' . preg_replace('/[^A-Za-z]/', '', (string)($t['f'] ?? ''));

$over = []; $soon = [];
foreach ($tasks as $t) {
    if (!is_array($t) || !$isDate($t['over'] ?? null) || !$isDate($t['due'] ?? null)) continue;
    if ($t['over'] <= $today) $over[] = $t;
    elseif ($t['due'] <= $week) $soon[] = $t;
}
usort($over, fn($a, $b) => strcmp($a['due'], $b['due']));
usort($soon, fn($a, $b) => strcmp($a['due'], $b['due']));
$updated = (new DateTimeImmutable('now', $tz))->format(DATE_ATOM);

if ($id === 'overdue') {
    $n = count($over);
    tile_out(200, [
        'title'   => 'Overdue maintenance',
        'value'   => $n === 0 ? 'None overdue' : $n . ' overdue',
        'status'  => $n === 0 ? 'ok' : 'alert',
        'items'   => array_map(fn($t) => ['text' => $label($t) . ' · was due ' . $fmt($t['due'], false), 'href' => $base . '/#over'], array_slice($over, 0, 8)),
        'link'    => $base . '/#over',
        'updated' => $updated,
    ]);
}

$n = count($soon);
$dueToday = count(array_filter($soon, fn($t) => $t['due'] <= $today));
tile_out(200, [
    'title'   => 'Due this week',
    'value'   => $n === 0 ? 'Nothing due' : $n . ' due',
    'status'  => $dueToday > 0 ? 'warn' : 'ok',
    'items'   => array_map(fn($t) => ['text' => $label($t) . ' · due ' . ($t['due'] <= $today ? 'today' : $fmt($t['due'], true)), 'href' => $base . '/#soon'], array_slice($soon, 0, 8)),
    'link'    => $base . '/#soon',
    'updated' => $updated,
]);
