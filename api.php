<?php
/*
 * TDC Maintenance Tracker – data API
 *
 * A small JSON document store on top of MySQL (or SQLite for local testing),
 * protected by one shared office password. The page (index.html) talks to it
 * with POST requests; every request except "login" and "status" needs the
 * sign-in cookie this file hands out.
 *
 * Settings live in config.php (copy config.sample.php). Tables are created
 * automatically on first use.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

const COLLECTIONS = ['tasks', 'log', 'settings'];
const COOKIE      = 'tdc_auth';
const TOKEN_DAYS  = 90;
const MAX_FAILS   = 10;      // failed sign-ins per IP …
const FAIL_WINDOW = 900;     // … per 15 minutes
const MAX_DOC     = 262144;  // 256 KB per document

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $code, string $error, string $message): void {
    out($code, ['ok' => false, 'error' => $error, 'message' => $message]);
}

/* ---------- configuration ---------- */
$cfgFile = __DIR__ . '/config.php';
if (!is_file($cfgFile)) {
    fail(500, 'not_configured', 'The tracker is not set up yet: create config.php from config.sample.php.');
}
require $cfgFile;
foreach (['OFFICE_PASSWORD'] as $c) {
    if (!defined($c) || (string)constant($c) === '' || constant($c) === 'change-me') {
        fail(500, 'not_configured', 'Set OFFICE_PASSWORD in config.php.');
    }
}

/* ---------- request guard ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail(405, 'method', 'Use POST.');
// Only the tracker page sends this header; a form on another site cannot.
if (($_SERVER['HTTP_X_TDC'] ?? '') !== '1') fail(400, 'bad_request', 'Missing request header.');
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 2 * 1024 * 1024) fail(413, 'too_large', 'Request too large.');
$req = json_decode($raw, true);
if (!is_array($req) || !isset($req['op']) || !is_string($req['op'])) fail(400, 'bad_request', 'Bad request.');
$op = $req['op'];

/* ---------- database ---------- */
try {
    if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') {
        $pdo = new PDO('sqlite:' . DB_SQLITE_PATH);
        $driver = 'sqlite';
    } else {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_EMULATE_PREPARES => false]
        );
        $driver = 'mysql';
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fail(500, 'db_connect', 'Could not connect to the database. Check the DB_ settings in config.php.');
}

function ensure_schema(PDO $pdo, string $driver): void {
    $cs = $driver === 'mysql' ? ' DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS tdc_docs (
        col VARCHAR(32) NOT NULL,
        id VARCHAR(191) NOT NULL,
        data LONGTEXT NOT NULL,
        at_key VARCHAR(64) NULL,
        rev BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY (col, id)
    )$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tdc_meta (
        k VARCHAR(32) NOT NULL PRIMARY KEY,
        v BIGINT NOT NULL DEFAULT 0
    )$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tdc_tokens (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        pw_fp CHAR(16) NOT NULL,
        expires BIGINT NOT NULL
    )$cs");
    $pdo->exec("CREATE TABLE IF NOT EXISTS tdc_fails (
        ip VARCHAR(64) NOT NULL,
        ts BIGINT NOT NULL
    )$cs");
    // Indexes: ignore "already exists" so this stays safe to run every time.
    foreach (['CREATE INDEX tdc_docs_at ON tdc_docs (col, at_key)',
              'CREATE INDEX tdc_fails_ip ON tdc_fails (ip, ts)'] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { /* exists */ }
    }
    $has = $pdo->query("SELECT COUNT(*) AS n FROM tdc_meta WHERE k = 'rev'")->fetch();
    if ((int)$has['n'] === 0) $pdo->exec("INSERT INTO tdc_meta (k, v) VALUES ('rev', 0)");
}
try { ensure_schema($pdo, $driver); }
catch (Throwable $e) { fail(500, 'db_schema', 'Could not set up the database tables.'); }

/* ---------- sign-in ---------- */
function pw_fp(): string { return substr(hash('sha256', 'tdc|' . OFFICE_PASSWORD), 0, 16); }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function set_cookie(string $value, int $expires): void {
    setcookie(COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'secure' => is_https(),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
}
function signed_in(PDO $pdo): bool {
    $t = $_COOKIE[COOKIE] ?? '';
    if (!is_string($t) || !preg_match('/^[a-f0-9]{64}$/', $t)) return false;
    $st = $pdo->prepare('SELECT pw_fp, expires FROM tdc_tokens WHERE token_hash = ?');
    $st->execute([hash('sha256', $t)]);
    $row = $st->fetch();
    // A changed office password signs every device out (pw_fp no longer matches).
    return $row && $row['pw_fp'] === pw_fp() && (int)$row['expires'] > time();
}

if ($op === 'status') {
    out(200, ['ok' => true, 'signedIn' => signed_in($pdo)]);
}

if ($op === 'login') {
    $ip = client_ip(); $now = time();
    $pdo->prepare('DELETE FROM tdc_fails WHERE ts < ?')->execute([$now - FAIL_WINDOW]);
    $st = $pdo->prepare('SELECT COUNT(*) AS n FROM tdc_fails WHERE ip = ?');
    $st->execute([$ip]);
    if ((int)$st->fetch()['n'] >= MAX_FAILS) {
        fail(429, 'too_many', 'Too many wrong passwords. Wait 15 minutes and try again.');
    }
    $pw = $req['password'] ?? '';
    if (!is_string($pw) || !hash_equals((string)OFFICE_PASSWORD, $pw)) {
        $pdo->prepare('INSERT INTO tdc_fails (ip, ts) VALUES (?, ?)')->execute([$ip, $now]);
        usleep(600000);
        fail(401, 'wrong_password', 'That password isn\'t right.');
    }
    $token = bin2hex(random_bytes(32));
    $exp = $now + TOKEN_DAYS * 86400;
    $pdo->prepare('DELETE FROM tdc_tokens WHERE expires < ?')->execute([$now]);
    $pdo->prepare('INSERT INTO tdc_tokens (token_hash, pw_fp, expires) VALUES (?, ?, ?)')
        ->execute([hash('sha256', $token), pw_fp(), $exp]);
    set_cookie($token, $exp);
    out(200, ['ok' => true]);
}

if ($op === 'logout') {
    $t = $_COOKIE[COOKIE] ?? '';
    if (is_string($t) && $t !== '') {
        $pdo->prepare('DELETE FROM tdc_tokens WHERE token_hash = ?')->execute([hash('sha256', $t)]);
    }
    set_cookie('', 1);
    out(200, ['ok' => true]);
}

if (!signed_in($pdo)) fail(401, 'signed_out', 'Please sign in again.');

/* ---------- documents ---------- */
function col_ok($c): string {
    if (!is_string($c) || !in_array($c, COLLECTIONS, true)) fail(400, 'invalid_argument', 'Unknown collection.');
    return $c;
}
function id_ok($id): string {
    if (!is_string($id) || !preg_match('/^[A-Za-z0-9_\-.~:@+]{1,191}$/', $id) || $id === '.' || $id === '..') {
        fail(400, 'invalid_argument', 'Bad document id.');
    }
    return $id;
}
function data_ok($d): array {
    if (!is_array($d) || (count($d) > 0 && array_is_list($d))) fail(400, 'invalid_argument', 'Document must be an object.');
    return $d;
}
function enc(array $d): string {
    $j = json_encode((object)$d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false || strlen($j) > MAX_DOC) fail(400, 'invalid_argument', 'Document too large.');
    return $j;
}
function at_key(array $d): ?string { return (isset($d['at']) && is_string($d['at'])) ? substr($d['at'], 0, 64) : null; }
function bump(PDO $pdo): int {
    $pdo->exec("UPDATE tdc_meta SET v = v + 1 WHERE k = 'rev'");
    return (int)$pdo->query("SELECT v FROM tdc_meta WHERE k = 'rev'")->fetch()['v'];
}
function current_rev(PDO $pdo): int { return (int)$pdo->query("SELECT v FROM tdc_meta WHERE k = 'rev'")->fetch()['v']; }
function load(PDO $pdo, string $col, string $id): ?array {
    $st = $pdo->prepare('SELECT data FROM tdc_docs WHERE col = ? AND id = ?');
    $st->execute([$col, $id]);
    $r = $st->fetch();
    return $r ? (json_decode($r['data'], true) ?: []) : null;
}
function save(PDO $pdo, string $col, string $id, array $d): int {
    $pdo->beginTransaction();
    try {
        $rev = bump($pdo);
        $st = $pdo->prepare('SELECT COUNT(*) AS n FROM tdc_docs WHERE col = ? AND id = ?');
        $st->execute([$col, $id]);
        if ((int)$st->fetch()['n'] > 0) {
            $pdo->prepare('UPDATE tdc_docs SET data = ?, at_key = ?, rev = ? WHERE col = ? AND id = ?')
                ->execute([enc($d), at_key($d), $rev, $col, $id]);
        } else {
            $pdo->prepare('INSERT INTO tdc_docs (col, id, data, at_key, rev) VALUES (?, ?, ?, ?, ?)')
                ->execute([$col, $id, enc($d), at_key($d), $rev]);
        }
        $pdo->commit();
        return $rev;
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

try {
    switch ($op) {
        case 'rev':
            out(200, ['ok' => true, 'rev' => current_rev($pdo)]);

        case 'get': {
            $col = col_ok($req['col'] ?? null); $id = id_ok($req['id'] ?? null);
            $d = load($pdo, $col, $id);
            out(200, ['ok' => true, 'exists' => $d !== null, 'data' => $d === null ? null : (object)$d, 'rev' => current_rev($pdo)]);
        }

        case 'query': {
            $col = col_ok($req['col'] ?? null);
            $sql = 'SELECT id, data FROM tdc_docs WHERE col = ?'; $args = [$col];
            foreach (($req['where'] ?? []) as $w) {
                // Only the time stamp field "at" is filterable; it has its own column.
                if (!is_array($w) || count($w) !== 3 || $w[0] !== 'at' || !is_string($w[2])) fail(400, 'invalid_argument', 'Unsupported filter.');
                $ops = ['<' => '<', '<=' => '<=', '>' => '>', '>=' => '>=', '==' => '='];
                if (!isset($ops[$w[1]])) fail(400, 'invalid_argument', 'Unsupported filter.');
                $sql .= ' AND at_key IS NOT NULL AND at_key ' . $ops[$w[1]] . ' ?'; $args[] = $w[2];
            }
            $ob = $req['orderBy'] ?? null;
            if ($ob !== null) {
                if (!is_array($ob) || ($ob[0] ?? '') !== 'at') fail(400, 'invalid_argument', 'Unsupported order.');
                $sql .= ' ORDER BY at_key ' . ((($ob[1] ?? 'asc') === 'desc') ? 'DESC' : 'ASC') . ', id';
            } else {
                $sql .= ' ORDER BY id';
            }
            $lim = (int)($req['limit'] ?? 1000);
            $lim = max(1, min(1000, $lim));
            $sql .= ' LIMIT ' . $lim;
            $st = $pdo->prepare($sql); $st->execute($args);
            $docs = [];
            foreach ($st->fetchAll() as $r) $docs[] = ['id' => $r['id'], 'data' => (object)(json_decode($r['data'], true) ?: [])];
            out(200, ['ok' => true, 'docs' => $docs, 'rev' => current_rev($pdo)]);
        }

        case 'set': {
            $col = col_ok($req['col'] ?? null); $id = id_ok($req['id'] ?? null); $d = data_ok($req['data'] ?? null);
            out(200, ['ok' => true, 'rev' => save($pdo, $col, $id, $d)]);
        }

        case 'add': {
            $col = col_ok($req['col'] ?? null); $d = data_ok($req['data'] ?? null);
            $id = substr(bin2hex(random_bytes(10)), 0, 20);
            out(200, ['ok' => true, 'id' => $id, 'rev' => save($pdo, $col, $id, $d)]);
        }

        case 'update': {
            $col = col_ok($req['col'] ?? null); $id = id_ok($req['id'] ?? null); $patch = data_ok($req['data'] ?? null);
            $cur = load($pdo, $col, $id);
            if ($cur === null) fail(400, 'invalid_argument', 'That item no longer exists.');
            foreach ($patch as $k => $v) $cur[$k] = $v;   // top-level fields replace
            out(200, ['ok' => true, 'rev' => save($pdo, $col, $id, $cur)]);
        }

        case 'delete': {
            $col = col_ok($req['col'] ?? null); $id = id_ok($req['id'] ?? null);
            $pdo->beginTransaction();
            $rev = bump($pdo);
            $pdo->prepare('DELETE FROM tdc_docs WHERE col = ? AND id = ?')->execute([$col, $id]);
            $pdo->commit();
            out(200, ['ok' => true, 'rev' => $rev]);
        }

        default:
            fail(400, 'bad_request', 'Unknown operation.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('tdc api: ' . $e->getMessage());
    fail(503, 'unavailable', 'The server had a problem saving or loading. Try again.');
}
