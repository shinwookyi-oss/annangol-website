<?php
// AnnanGol first-party analytics: shared helpers (no output).
// Data lives OUTSIDE public_html when possible so a Git deploy never touches it.
if (!defined('AG_ANALYTICS')) { http_response_code(404); exit; }

date_default_timezone_set('America/New_York');

// Dashboard password hash (bcrypt). Change by replacing this hash, or by putting
// {"password_hash":"..."} in <data dir>/config.json (that file wins).
const AG_DEFAULT_PASSWORD_HASH = '$2y$12$2ygWlK9HubwAvjyiTaA0B.8M6CvlXYrF9gJ1gPaThcxCLSEBvn8KW';

function ag_data_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    $candidates = [
        dirname(__DIR__, 2) . '/analytics-data',   // /home/<u>/domains/annangol.com/analytics-data
        __DIR__ . '/.data',                         // fallback inside api/ (blocked by .htaccess)
    ];
    foreach ($candidates as $c) {
        if (is_dir($c) || @mkdir($c, 0700, true)) {
            if (is_writable($c)) { $dir = $c; return $dir; }
        }
    }
    throw new RuntimeException('No writable analytics data directory');
}

function ag_config(): array {
    $f = ag_data_dir() . '/config.json';
    $cfg = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $cfg['password_hash'] = $cfg['password_hash'] ?? AG_DEFAULT_PASSWORD_HASH;
    return $cfg;
}

function ag_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . ag_data_dir() . '/analytics.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=3000;');
    $db->exec('CREATE TABLE IF NOT EXISTS hits(
        id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, day TEXT NOT NULL, path TEXT NOT NULL,
        vid TEXT NOT NULL, ref TEXT, src TEXT, med TEXT, camp TEXT, device TEXT, lang TEXT)');
    $db->exec('CREATE TABLE IF NOT EXISTS events(
        id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, day TEXT NOT NULL, path TEXT NOT NULL,
        vid TEXT NOT NULL, name TEXT NOT NULL, target TEXT)');
    $db->exec('CREATE INDEX IF NOT EXISTS hits_day ON hits(day, path)');
    $db->exec('CREATE INDEX IF NOT EXISTS events_day ON events(day, name)');
    return $db;
}

// Daily-rotating salt: visitors are counted per day without storing IPs.
function ag_daily_salt(string $day): string {
    $f = ag_data_dir() . '/salt.json';
    $s = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if (($s['day'] ?? '') !== $day) {
        $s = ['day' => $day, 'salt' => bin2hex(random_bytes(16))];
        @file_put_contents($f, json_encode($s), LOCK_EX);
    }
    return $s['salt'];
}

function ag_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', $_SERVER[$k])[0]);
    }
    return '0.0.0.0';
}

function ag_is_bot(string $ua): bool {
    return $ua === '' || (bool)preg_match('~bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|monitor|curl|wget|python|axios|httpclient|facebookexternalhit|embedly|whatsapp|telegram~i', $ua);
}

function ag_device(string $ua): string {
    if (preg_match('~ipad|tablet|kindle|silk|(android(?!.*mobile))~i', $ua)) return 'tablet';
    if (preg_match('~mobi|iphone|ipod|android~i', $ua)) return 'mobile';
    return 'desktop';
}

function ag_clean(?string $v, int $max = 120): ?string {
    if ($v === null) return null;
    $v = trim(preg_replace('~[\x00-\x1f]~', '', $v));
    return $v === '' ? null : mb_substr($v, 0, $max);
}
