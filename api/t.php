<?php
// AnnanGol first-party analytics collector. Receives navigator.sendBeacon() posts.
define('AG_ANALYTICS', 1);
require __DIR__ . '/lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if (ag_is_bot($ua) || !empty($_COOKIE['ag_notrack'])) { http_response_code(204); exit; }

// Only count posts from our own site.
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
$host = strtolower((string)parse_url($origin, PHP_URL_HOST));
$self = strtolower(preg_replace(['~^www\.~', '~:\d+$~'], '', $_SERVER['HTTP_HOST'] ?? ''));
if ($host !== '' && preg_replace('~^www\.~', '', $host) !== $self) { http_response_code(204); exit; }

$raw = file_get_contents('php://input', false, null, 0, 4096);
$in = json_decode((string)$raw, true);
if (!is_array($in)) { http_response_code(400); exit; }

$path = (string)($in['p'] ?? '/');
$path = '/' . ltrim(preg_replace('~[?#].*$~', '', $path), '/');
$path = preg_replace('~/index\.html$~', '/', $path);
if (!preg_match('~^/[a-z0-9/_-]{0,60}$~i', $path)) $path = '/(other)';

$now = time();
$day = date('Y-m-d', $now);
$vid = substr(hash('sha256', ag_daily_salt($day) . '|' . ag_client_ip() . '|' . $ua), 0, 16);

try {
    $db = ag_db();
    if (($in['t'] ?? '') === 'ev') {
        $name = ag_clean($in['n'] ?? null, 40);
        if (!$name || !preg_match('~^[a-z_]+$~', $name)) { http_response_code(400); exit; }
        $st = $db->prepare('INSERT INTO events(ts,day,path,vid,name,target) VALUES(?,?,?,?,?,?)');
        $st->execute([$now, $day, $path, $vid, $name, ag_clean($in['x'] ?? null, 200)]);
    } else {
        $ref = null;
        $rh = strtolower((string)parse_url((string)($in['r'] ?? ''), PHP_URL_HOST));
        $rh = preg_replace('~^(www|m|l|lm)\.~', '', $rh);
        if ($rh !== '' && $rh !== $self) $ref = ag_clean($rh, 100);
        parse_str(ltrim((string)($in['q'] ?? ''), '?'), $q);
        $st = $db->prepare('INSERT INTO hits(ts,day,path,vid,ref,src,med,camp,device,lang) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $st->execute([$now, $day, $path, $vid, $ref,
            ag_clean($q['utm_source'] ?? (isset($q['gclid']) ? 'google-ads' : null), 60),
            ag_clean($q['utm_medium'] ?? (isset($q['gclid']) ? 'cpc' : null), 60),
            ag_clean($q['utm_campaign'] ?? null, 80),
            ag_device($ua), ag_clean(substr((string)($in['l'] ?? ''), 0, 5), 5)]);
    }
    http_response_code(204);
} catch (Throwable $e) {
    error_log('ag analytics: ' . $e->getMessage());
    http_response_code(500);
}
