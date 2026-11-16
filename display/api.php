<?php
// AnnanGol in-store display: state, admin login and media uploads.
// Data (state.json, uploaded media) lives OUTSIDE public_html when possible so a
// Git deploy never touches it. The admin password hash is DISPLAY_PASSWORD_HASH below
// unless <display data dir>/config.json holds its own {"password_hash":"..."}.
declare(strict_types=1);
define('AG_ANALYTICS', 1);
require __DIR__ . '/../api/lib.php';

const DISPLAY_PASSWORD_HASH = '$2y$12$OF0WDJgnFaZxD4H1ZCrZQukwA5j6auIYFXmlAaO3EmfSWFIw1EDry';
const DISPLAY_MAX_STATE_BYTES = 600000;
const DISPLAY_EXT = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
                     'gif' => 'image/gif', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime'];

function display_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;
    foreach ([dirname(__DIR__, 2) . '/display-data', __DIR__ . '/.data'] as $c) {
        if ((is_dir($c) || @mkdir($c, 0700, true)) && is_writable($c)) {
            if (!is_dir("$c/media")) @mkdir("$c/media", 0700, true);
            return $dir = $c;
        }
    }
    throw new RuntimeException('No writable display data directory');
}
function display_hash(): string {
    $f = display_dir() . '/config.json';
    $cfg = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    return (string)($cfg['password_hash'] ?? DISPLAY_PASSWORD_HASH);
}
function out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function start_session(): void {
    session_name('ag_display');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/display/', 'secure' => !empty($_SERVER['HTTPS']),
                               'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
function need_auth(): void {
    start_session();
    if (empty($_SESSION['ok'])) out(['error' => 'auth'], 401);
    session_write_close();
}
function need_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['error' => 'method'], 405);
}
// One-time import shipped with the site (display/import.json): schedule days and playlist
// changes are written into the saved state once per import id, so later admin edits are kept.
function apply_import(): void {
    $src = __DIR__ . '/import.json';
    if (!is_file($src)) return;
    $imp = json_decode((string)file_get_contents($src), true);
    if (!is_array($imp) || empty($imp['id'])) return;
    $mark = display_dir() . '/import-applied.txt';
    if (is_file($mark) && trim((string)file_get_contents($mark)) === (string)$imp['id']) return;
    $f = display_dir() . '/state.json';
    $state = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    if (is_array($imp['schedule'] ?? null)) {
        if (!is_array($state['schedule'] ?? null)) $state['schedule'] = [];
        foreach ($imp['schedule'] as $day => $entry) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$day) && is_array($entry)) $state['schedule'][$day] = $entry;
        }
    }
    // menu_default: menu settings that are filled in only when the admin has not set them
    if (is_array($imp['menu_default'] ?? null)) {
        if (!is_array($state['menu'] ?? null)) $state['menu'] = [];
        foreach ($imp['menu_default'] as $k => $v) { if (!array_key_exists($k, $state['menu'])) $state['menu'][$k] = $v; }
    }
    if (is_array($state['items'] ?? null)) {
        // items_set: change fields of existing items by id (null removes a field)
        if (is_array($imp['items_set'] ?? null)) {
            foreach ($state['items'] as &$it) {
                $set = $imp['items_set'][$it['id'] ?? ''] ?? null;
                if (!is_array($set)) continue;
                foreach ($set as $k => $v) { if ($v === null) unset($it[$k]); else $it[$k] = $v; }
            }
            unset($it);
        }
        // items_add: append items whose id is not there yet
        if (is_array($imp['items_add'] ?? null)) {
            $have = array_column($state['items'], 'id');
            foreach ($imp['items_add'] as $n) {
                if (is_array($n) && !empty($n['id']) && !in_array($n['id'], $have, true)) $state['items'][] = $n;
            }
        }
    }
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents("$f.tmp", $json, LOCK_EX) !== false && rename("$f.tmp", $f)) file_put_contents($mark, (string)$imp['id'], LOCK_EX);
    clearstatcache();
}
// A per-day visitor id (no IP is stored), used only to stop repeat likes and floods of suggestions.
function visitor_id(): string {
    return substr(hash('sha256', ag_daily_salt(gmdate('Y-m-d')) . '|' . ag_client_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 16);
}
// Read-modify-write a small JSON file under an exclusive lock.
function json_update(string $file, callable $fn) {
    $fh = fopen($file, 'c+');
    if (!$fh) throw new RuntimeException('open');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = $raw !== '' && $raw !== false ? (json_decode($raw, true) ?: []) : [];
    $res = $fn($data);
    ftruncate($fh, 0); rewind($fh);
    fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    return $res;
}
function json_read(string $file): array {
    return is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
}
function clip($v, int $max): string {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)$v) ?? '');
    return function_exists('mb_substr') ? mb_substr($s, 0, $max) : substr($s, 0, $max);
}
function media_name(string $f): ?string {
    return preg_match('/^[a-f0-9]{16}\.(' . implode('|', array_keys(DISPLAY_EXT)) . ')$/', $f) ? $f : null;
}

try {
    $a = (string)($_GET['a'] ?? 'state');

    if ($a === 'file') {                                   // serve an uploaded file (with Range for video)
        $name = media_name((string)($_GET['f'] ?? ''));
        $path = $name ? display_dir() . '/media/' . $name : '';
        if (!$name || !is_file($path)) { http_response_code(404); exit; }
        $size = filesize($path);
        $start = 0; $end = $size - 1;
        header('Content-Type: ' . DISPLAY_EXT[pathinfo($name, PATHINFO_EXTENSION)]);
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
            else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
            if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') exit;
        while (ob_get_level()) ob_end_clean();
        $fh = fopen($path, 'rb'); fseek($fh, $start);
        $left = $end - $start + 1;
        while ($left > 0 && !feof($fh) && !connection_aborted()) {
            $buf = fread($fh, (int)min(262144, $left)); if ($buf === false) break;
            echo $buf; flush(); $left -= strlen($buf);
        }
        fclose($fh); exit;
    }

    if ($a === 'state') {                                  // public: what the board plays
        apply_import();
        $f = display_dir() . '/state.json';
        $raw = is_file($f) ? (string)file_get_contents($f) : '';
        $state = $raw !== '' ? json_decode($raw, true) : null;
        out(['state' => $state, 'likes' => (object)(json_read(display_dir() . '/likes.json')['counts'] ?? []),
             'version' => $raw !== '' ? (string)filemtime($f) . '-' . strlen($raw) : '0',
             'app' => (string)@filemtime(__DIR__ . '/index.html')]);   // lets open screens notice a new page version
    }

    if ($a === 'like') {                                   // public: "I want this" on a dish of the weekly menu
        need_post();
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $dish = clip($in['dish'] ?? '', 60);
        $state = json_read(display_dir() . '/state.json');
        $known = [];
        foreach (($state['schedule'] ?? []) as $e) { if (is_array($e) && trim((string)($e['main'] ?? '')) !== '') $known[trim((string)$e['main'])] = 1; }
        if ($dish === '' || !isset($known[$dish])) out(['error' => 'dish'], 400);
        if (ag_is_bot((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) out(['ok' => true, 'count' => 0]);
        $day = gmdate('Y-m-d'); $key = visitor_id() . '|' . $dish;
        $count = json_update(display_dir() . '/likes.json', function (&$d) use ($day, $key, $dish) {
            if (!is_array($d['counts'] ?? null)) $d['counts'] = [];
            if (($d['day'] ?? '') !== $day) { $d['day'] = $day; $d['seen'] = []; }   // one like per visitor, dish and day
            if (empty($d['seen'][$key])) { $d['seen'][$key] = 1; $d['counts'][$dish] = (int)($d['counts'][$dish] ?? 0) + 1; }
            return (int)$d['counts'][$dish];
        });
        out(['ok' => true, 'count' => $count]);
    }

    if ($a === 'suggest') {                                // public: private suggestion box
        need_post();
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        if (trim((string)($in['website'] ?? '')) !== '' || ag_is_bot((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) out(['ok' => true]);   // bots: accept and drop
        $text = clip($in['text'] ?? '', 600);
        if ((function_exists('mb_strlen') ? mb_strlen($text) : strlen($text)) < 2) out(['error' => 'text'], 400);
        $day = gmdate('Y-m-d'); $vid = visitor_id();
        $allowed = json_update(display_dir() . '/feedback-rate.json', function (&$d) use ($day, $vid) {
            if (($d['day'] ?? '') !== $day) $d = ['day' => $day, 'n' => []];
            $d['n'][$vid] = (int)($d['n'][$vid] ?? 0) + 1;
            return $d['n'][$vid] <= 5 && count($d['n']) <= 300;   // 5 per visitor and 300 senders per day
        });
        if (!$allowed) out(['error' => 'rate'], 429);
        $row = ['id' => bin2hex(random_bytes(6)), 't' => gmdate('c'), 'kind' => ($in['kind'] ?? '') === 'comment' ? 'comment' : 'menu',
                'text' => $text, 'name' => clip($in['name'] ?? '', 60), 'contact' => clip($in['contact'] ?? '', 100), 'lang' => clip($in['lang'] ?? '', 5)];
        json_update(display_dir() . '/feedback.json', function (&$d) use ($row) {
            if (!is_array($d['items'] ?? null)) $d['items'] = [];
            $d['items'][] = $row;
            if (count($d['items']) > 500) $d['items'] = array_slice($d['items'], -500);
        });
        out(['ok' => true]);
    }

    if ($a === 'feedback') {                               // admin: read suggestions and likes
        need_auth();
        $items = array_reverse(json_read(display_dir() . '/feedback.json')['items'] ?? []);
        $likes = json_read(display_dir() . '/likes.json')['counts'] ?? [];
        arsort($likes);
        out(['items' => $items, 'likes' => (object)$likes]);
    }
    if ($a === 'feedback_del') {
        need_post(); need_auth();
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $id = (string)($in['id'] ?? '');
        json_update(display_dir() . '/feedback.json', function (&$d) use ($id) {
            $d['items'] = array_values(array_filter($d['items'] ?? [], function ($r) use ($id) { return ($r['id'] ?? '') !== $id; }));
        });
        out(['ok' => true]);
    }

    if ($a === 'me') { start_session(); out(['authed' => !empty($_SESSION['ok'])]); }

    if ($a === 'login') {
        need_post(); start_session();
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        if (password_verify((string)($in['password'] ?? ''), display_hash())) {
            session_regenerate_id(true); $_SESSION['ok'] = 1; out(['authed' => true]);
        }
        sleep(1); out(['error' => 'password'], 403);
    }
    if ($a === 'logout') { need_post(); start_session(); $_SESSION = []; session_destroy(); out(['authed' => false]); }

    if ($a === 'save') {
        need_post(); need_auth();
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > DISPLAY_MAX_STATE_BYTES) out(['error' => 'too_large'], 413);
        $state = json_decode($raw, true);
        if (!is_array($state) || !isset($state['items']) || !is_array($state['items'])) out(['error' => 'bad_state'], 400);
        $f = display_dir() . '/state.json';
        // Refuse a save made from an out-of-date copy, so it cannot overwrite newer changes.
        clearstatcache();
        $cur = is_file($f) ? (string)filemtime($f) . '-' . filesize($f) : '0';
        if (isset($_GET['v']) && (string)$_GET['v'] !== $cur) out(['error' => 'stale'], 409);
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents("$f.tmp", $json, LOCK_EX) === false || !rename("$f.tmp", $f)) out(['error' => 'write'], 500);
        clearstatcache();
        out(['ok' => true, 'version' => (string)filemtime($f) . '-' . strlen($json)]);
    }

    if ($a === 'upload') {
        need_post(); need_auth();
        $up = $_FILES['file'] ?? null;
        if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $too = in_array($up['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || !$up;
            out(['error' => $too ? 'too_large' : 'upload', 'limit' => ini_get('upload_max_filesize')], 400);
        }
        $ext = strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION));
        if (!isset(DISPLAY_EXT[$ext])) out(['error' => 'type'], 400);
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($up['tmp_name']);
        if (strpos($mime, 'image/') !== 0 && strpos($mime, 'video/') !== 0) out(['error' => 'type'], 400);
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($up['tmp_name'], display_dir() . '/media/' . $name)) out(['error' => 'write'], 500);
        out(['ok' => true, 'file' => $name, 'name' => (string)$up['name'], 'size' => (int)$up['size']]);
    }

    if ($a === 'delete') {
        need_post(); need_auth();
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $name = media_name((string)($in['file'] ?? ''));
        if ($name) @unlink(display_dir() . '/media/' . $name);
        out(['ok' => true]);
    }

    // ---------------- Surveys and coupon campaigns ----------------
    // survey/surveys.json defines the surveys; coupon campaigns are created in the admin. Answers, campaigns and coupons live in the data dir.
    // A row's 's' is a survey id, or 'k:<id>' for a coupon issued by a campaign. Every coupon keeps the offer it was issued with ('c').
    if (strpos($a, 'survey_') === 0 || strpos($a, 'campaign_') === 0) {
        $defs = json_decode((string)@file_get_contents(__DIR__ . '/../survey/surveys.json'), true) ?: [];
        $file = display_dir() . '/survey.json';
        $today = date('Y-m-d');
        $store = json_read($file);
        if (empty($store['secret'])) {
            json_update($file, function (&$d) { if (empty($d['secret'])) $d['secret'] = bin2hex(random_bytes(16)); if (!is_array($d['rows'] ?? null)) $d['rows'] = []; });
            $store = json_read($file);
        }
        $camps = is_array($store['campaigns'] ?? null) ? $store['campaigns'] : [];
        $is_date = function ($v) { return is_string($v) && preg_match('/^(\d{4}-\d{2}-\d{2})?$/', $v); };
        $label = function (array $c) {
            $v = rtrim(rtrim(number_format((float)($c['value'] ?? 0), 2, '.', ''), '0'), '.');
            $x = ($c['kind'] ?? 'amount') === 'percent' ? "$v%" : "\$$v";
            return ['ko' => "$x 할인권", 'en' => "$x OFF coupon", 'es' => "Cupón de $x de descuento", 'zh' => "$x 优惠券"];
        };
        $offer_in = function (array $in) use ($is_date) {        // an offer as typed in the admin; null when it is not valid
            $kind = ($in['kind'] ?? '') === 'percent' ? 'percent' : 'amount'; $v = round((float)($in['value'] ?? 0), 2);
            if ($v <= 0 || $v > ($kind === 'percent' ? 100 : 1000) || !$is_date($in['expires'] ?? '')) return null;
            return ['kind' => $kind, 'value' => $v, 'expires' => (string)($in['expires'] ?? ''),
                    'terms' => ['ko' => clip($in['terms']['ko'] ?? '', 200), 'en' => clip($in['terms']['en'] ?? '', 200)]];
        };
        $survey_offer = function (string $id) use ($defs, $store) {   // what a survey issues now: the admin's setting, else the definition's
            $o = $store['cfg'][$id]['coupon'] ?? null;
            if (is_array($o)) return empty($o['on']) ? null : ['kind' => $o['kind'], 'value' => $o['value'], 'expires' => $o['expires'], 'terms' => $o['terms']];
            $b = $defs[$id]['coupon'] ?? null;
            return $b ? ['kind' => $b['kind'] ?? 'amount', 'value' => $b['value'] ?? 0, 'expires' => $b['expires'] ?? '', 'terms' => $b['terms'] ?? []] : null;
        };
        $merged = function (string $id) use ($defs, $store, $survey_offer, $label) {      // definition + admin overrides
            if (!isset($defs[$id])) return null;
            $d = $defs[$id]; $o = $store['cfg'][$id] ?? [];
            foreach (['on', 'start', 'end'] as $k) { if (array_key_exists($k, $o)) $d[$k] = $o[$k]; }
            $d['coupon'] = $survey_offer($id);
            if ($d['coupon']) $d['coupon']['label'] = $label($d['coupon']);
            return $d;
        };
        $issued = function (string $s) use ($store) { $n = 0; foreach (($store['rows'] ?? []) as $r) { if ($r['s'] === $s) $n++; } return $n; };
        $status = function (array $d, ?string $s = null) use ($today, $issued) {
            if (empty($d['on'])) return 'off';
            if (!empty($d['start']) && $today < $d['start']) return 'soon';
            if (!empty($d['end']) && $today > $d['end']) return 'ended';
            if ($s !== null && !empty($d['limit']) && $issued($s) >= (int)$d['limit']) return 'full';
            return 'open';
        };
        $daycode = function (string $id, string $day) use ($store) {
            return str_pad((string)(hexdec(substr(hash_hmac('sha256', "$id|$day", (string)$store['secret']), 0, 7)) % 10000), 4, '0', STR_PAD_LEFT);
        };
        $coupon_view = function (array $r) use ($defs, $today, $label) {
            $b = $defs[$r['s']]['coupon'] ?? [];                       // coupons issued before offers were recorded on the coupon
            $c = is_array($r['c'] ?? null) ? $r['c'] : ['kind' => $b['kind'] ?? 'amount', 'value' => $b['value'] ?? 0, 'expires' => $b['expires'] ?? '', 'terms' => $b['terms'] ?? []];
            $st = !empty($r['used']) ? 'used' : ((!empty($c['expires']) && $today > $c['expires']) ? 'expired' : 'valid');
            return ['code' => $r['coupon'], 'status' => $st, 'used' => $r['used'] ?? null, 'issued' => $r['day'], 'survey' => $r['s'],
                    'label' => $label($c), 'title' => $c['title'] ?? null, 'terms' => $c['terms'] ?: null, 'expires' => $c['expires'] ?: null];
        };
        $add_row = function (array &$row, ?array $offer, int $limit = 0, bool $once = true) use ($file) {   // store a row, issuing its coupon; 'ok', 'dup', 'full'
            return json_update($file, function (&$st) use (&$row, $offer, $limit, $once) {
                if (!is_array($st['rows'] ?? null)) $st['rows'] = [];
                $codes = []; $n = 0;
                foreach ($st['rows'] as $r) {
                    if ($r['s'] === $row['s']) { $n++; if ($once && $row['phone'] !== '' && ($r['phone'] ?? '') === $row['phone']) return 'dup'; }   // one per phone number
                    if (!empty($r['coupon'])) $codes[$r['coupon']] = 1;
                }
                if (($limit && $n >= $limit) || count($st['rows']) >= 20000) return 'full';
                if ($offer) {
                    $abc = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
                    do { $c = 'AG-'; for ($i = 0; $i < 6; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)]; } while (isset($codes[$c]));
                    $row['coupon'] = $c; $row['c'] = $offer;
                }
                $st['rows'][] = $row; return 'ok';
            });
        };
        $phone_of = function ($v) { $p = preg_replace('/\D/', '', (string)$v); return (strlen($p) === 11 && $p[0] === '1') ? substr($p, 1) : $p; };
        $in = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];

        if ($a === 'survey_info') {                        // public: one survey, one campaign, or the list of open surveys
            $kid = (string)($_GET['k'] ?? '');
            if ($kid !== '') {
                $k = $camps[$kid] ?? null;
                if (!$k || empty($k['public'])) out(['error' => 'unknown'], 404);
                out(['id' => $kid, 'status' => $status($k, "k:$kid"), 'campaign' => ['title' => $k['title'], 'code' => !empty($k['code']),
                     'label' => $label($k['offer']), 'terms' => $k['offer']['terms'], 'expires' => $k['offer']['expires']]]);
            }
            $id = (string)($_GET['s'] ?? '');
            if ($id === '') {
                $list = [];
                foreach ($defs as $k => $_) { $d = $merged($k); if ($status($d) === 'open') $list[] = ['id' => $k, 'title' => $d['title'] ?? null]; }
                out(['surveys' => $list]);
            }
            $d = $merged($id);
            if (!$d) out(['error' => 'unknown'], 404);
            out(['id' => $id, 'status' => $status($d), 'survey' => $d]);
        }

        if ($a === 'survey_submit') {                      // public: answer a survey, or claim a campaign coupon (k)
            need_post();
            $kid = (string)($in['k'] ?? ''); $id = (string)($in['s'] ?? '');
            $d = $kid !== '' ? ($camps[$kid] ?? null) : $merged($id);
            if (!$d || ($kid !== '' && empty($d['public']))) out(['error' => 'unknown'], 404);
            $key = $kid !== '' ? "k:$kid" : $id;
            if (trim((string)($in['website'] ?? '')) !== '' || ag_is_bot((string)($_SERVER['HTTP_USER_AGENT'] ?? ''))) out(['error' => 'closed'], 400);
            if ($status($d, $kid !== '' ? $key : null) !== 'open') out(['error' => 'closed', 'status' => $status($d, $kid !== '' ? $key : null)], 400);
            $vid = visitor_id();
            $ok = json_update(display_dir() . '/survey-rate.json', function (&$r) use ($today, $vid) {   // slows down code guessing
                if (($r['day'] ?? '') !== $today) $r = ['day' => $today, 'n' => []];
                $r['n'][$vid] = (int)($r['n'][$vid] ?? 0) + 1;
                return $r['n'][$vid] <= 12 && count($r['n']) <= 500;
            });
            if (!$ok) out(['error' => 'rate'], 429);
            if (!empty($d['code']) && !hash_equals($daycode($key, $today), preg_replace('/\D/', '', (string)($in['code'] ?? '')))) out(['error' => 'code'], 400);
            $ans = [];
            foreach (($d['questions'] ?? []) as $q) {
                $v = $in['a'][$q['id']] ?? null; $opts = array_column($q['options'] ?? [], 'v');
                if ($q['type'] === 'single') { $v = in_array($v, $opts, true) ? $v : null; }
                elseif ($q['type'] === 'multi') { $v = array_values(array_intersect($opts, is_array($v) ? $v : [])); if (!$v) $v = null; }
                else { $v = clip($v ?? '', 600); if ($v === '') $v = null; }
                if ($v === null && !empty($q['required'])) out(['error' => 'missing', 'q' => $q['id']], 400);
                if ($v !== null) $ans[$q['id']] = $v;
            }
            $name = clip($in['name'] ?? '', 60); $phone = $phone_of($in['phone'] ?? '');
            if (($kid !== '' || !empty($d['contact'])) && ($name === '' || strlen($phone) !== 10)) out(['error' => 'contact'], 400);
            $row = ['id' => bin2hex(random_bytes(6)), 's' => $key, 't' => date('c'), 'day' => $today, 'lang' => clip($in['lang'] ?? '', 5), 'a' => $ans,
                    'name' => $name, 'phone' => $phone, 'sms' => !empty($in['sms'])];
            $res = $add_row($row, $kid !== '' ? $d['offer'] + ['title' => $d['title']] : $survey_offer($id), $kid !== '' ? (int)($d['limit'] ?? 0) : 0);
            if ($res !== 'ok') out(['error' => $res === 'full' ? 'closed' : $res, 'status' => $res], $res === 'dup' ? 409 : 400);
            out(['ok' => true, 'coupon' => isset($row['coupon']) ? $coupon_view($row) : null]);
        }

        if ($a === 'survey_coupon') {                      // public: show one coupon by its code
            $c = strtoupper(clip($_GET['c'] ?? '', 20));
            foreach (($store['rows'] ?? []) as $r) { if (($r['coupon'] ?? '') === $c && $c !== '') out(['coupon' => $coupon_view($r)]); }
            out(['error' => 'unknown'], 404);
        }

        need_auth();                                       // everything below is admin only
        if ($a === 'survey_admin') {
            $list = []; $klist = [];
            foreach ($defs as $k => $_) { $d = $merged($k); $list[] = ['id' => $k, 'status' => $status($d), 'today_code' => !empty($d['code']) ? $daycode($k, $today) : null, 'survey' => $d]; }
            foreach ($camps as $k => $c) { $klist[] = ['id' => $k, 'status' => $status($c, "k:$k"), 'today_code' => !empty($c['code']) ? $daycode("k:$k", $today) : null, 'label' => $label($c['offer']), 'campaign' => $c]; }
            $rows = array_reverse($store['rows'] ?? []);
            foreach ($rows as &$r) { if (!empty($r['coupon'])) $r['label'] = $coupon_view($r)['label']['ko']; }
            unset($r);
            out(['today' => $today, 'surveys' => $list, 'campaigns' => $klist, 'rows' => $rows]);
        }
        if ($a === 'survey_set') {                         // a survey's switch, period and coupon offer
            need_post(); $id = (string)($in['s'] ?? '');
            if (!isset($defs[$id])) out(['error' => 'unknown'], 404);
            $offer = null;
            if (is_array($in['coupon'] ?? null)) {
                if (empty($in['coupon']['on'])) $offer = ['on' => false];
                else { $offer = $offer_in($in['coupon']); if (!$offer) out(['error' => 'offer'], 400); $offer['on'] = true; }
            }
            json_update($file, function (&$st) use ($id, $in, $offer, $is_date) {
                if (array_key_exists('on', $in)) $st['cfg'][$id]['on'] = !empty($in['on']);
                foreach (['start', 'end'] as $k) { if (isset($in[$k]) && $is_date($in[$k])) $st['cfg'][$id][$k] = (string)$in[$k]; }
                if ($offer) $st['cfg'][$id]['coupon'] = $offer;
            });
            out(['ok' => true]);
        }
        if ($a === 'campaign_save') {                      // create (no id) or change a coupon campaign
            need_post(); $kid = (string)($in['id'] ?? '');
            if ($kid !== '' && !isset($camps[$kid])) out(['error' => 'unknown'], 404);
            $offer = $offer_in(is_array($in['offer'] ?? null) ? $in['offer'] : []);
            $title = ['ko' => clip($in['title']['ko'] ?? '', 80), 'en' => clip($in['title']['en'] ?? '', 80)];
            if (!$offer) out(['error' => 'offer'], 400);
            if ($title['ko'] === '' && $title['en'] === '') out(['error' => 'title'], 400);
            if (!$is_date($in['start'] ?? '') || !$is_date($in['end'] ?? '')) out(['error' => 'date'], 400);
            if ($kid === '') { if (count($camps) >= 200) out(['error' => 'full'], 400); $kid = 'c' . bin2hex(random_bytes(3)); }
            $c = ['title' => $title, 'on' => !empty($in['on']), 'start' => (string)($in['start'] ?? ''), 'end' => (string)($in['end'] ?? ''),
                  'public' => !empty($in['public']), 'code' => !empty($in['code']), 'limit' => max(0, min(100000, (int)($in['limit'] ?? 0))), 'offer' => $offer];
            json_update($file, function (&$st) use ($kid, $c) { $st['campaigns'][$kid] = $c; });
            out(['ok' => true, 'id' => $kid]);
        }
        if ($a === 'campaign_issue') {                     // staff hands a campaign coupon to one guest
            need_post(); $kid = (string)($in['id'] ?? ''); $k = $camps[$kid] ?? null;
            if (!$k) out(['error' => 'unknown'], 404);
            $name = clip($in['name'] ?? '', 60); $phone = $phone_of($in['phone'] ?? '');
            if ($phone !== '' && strlen($phone) !== 10) out(['error' => 'contact'], 400);
            $row = ['id' => bin2hex(random_bytes(6)), 's' => "k:$kid", 't' => date('c'), 'day' => $today, 'lang' => 'staff', 'a' => [], 'name' => $name, 'phone' => $phone, 'sms' => false];
            $res = $add_row($row, $k['offer'] + ['title' => $k['title']], 0, false);
            if ($res !== 'ok') out(['error' => $res], $res === 'dup' ? 409 : 400);
            out(['ok' => true, 'coupon' => $coupon_view($row)]);
        }
        if ($a === 'survey_redeem') {
            need_post(); $c = strtoupper(trim((string)($in['code'] ?? ''))); $undo = !empty($in['undo']);
            $res = json_update($file, function (&$st) use ($c, $undo) {
                foreach (array_keys($st['rows'] ?? []) as $i) {
                    if (($st['rows'][$i]['coupon'] ?? '') !== $c) continue;
                    if ($undo) { unset($st['rows'][$i]['used']); return $st['rows'][$i]; }
                    if (!empty($st['rows'][$i]['used'])) return ['already' => $st['rows'][$i]];
                    $st['rows'][$i]['used'] = date('c'); return $st['rows'][$i];
                }
                return null;
            });
            if (!$res) out(['error' => 'unknown'], 404);
            if (isset($res['already'])) out(['error' => 'used', 'coupon' => $coupon_view($res['already']), 'row' => $res['already']], 409);
            out(['ok' => true, 'coupon' => $coupon_view($res), 'row' => $res]);
        }
        if ($a === 'survey_del') {
            need_post(); $rid = (string)($in['id'] ?? '');
            json_update($file, function (&$st) use ($rid) { $st['rows'] = array_values(array_filter($st['rows'] ?? [], function ($r) use ($rid) { return ($r['id'] ?? '') !== $rid; })); });
            out(['ok' => true]);
        }
        if ($a === 'survey_csv') {
            $id = (string)($_GET['s'] ?? ''); $d = strpos($id, 'k:') === 0 ? ($camps[substr($id, 2)] ?? null) : $merged($id);
            if (!$d) out(['error' => 'unknown'], 404);
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="coupons-' . preg_replace('/[^a-z0-9]+/i', '-', $id) . '-' . $today . '.csv"'); header('Cache-Control: no-store');
            $fh = fopen('php://output', 'w'); fwrite($fh, "\xEF\xBB\xBF");
            $qs = $d['questions'] ?? [];
            $cell = function ($v) { $v = (string)$v; return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; };   // no spreadsheet formulas from guests
            fputcsv($fh, array_merge(['time', 'language'], array_column($qs, 'id'), ['name', 'phone', 'sms', 'coupon', 'offer', 'used']));
            foreach (($store['rows'] ?? []) as $r) {
                if ($r['s'] !== $id) continue;
                $line = [$r['t'], $r['lang'] ?? ''];
                foreach ($qs as $q) { $v = $r['a'][$q['id']] ?? ''; $line[] = $cell(is_array($v) ? implode(' | ', $v) : $v); }
                fputcsv($fh, array_merge($line, [$cell($r['name'] ?? ''), $r['phone'] ?? '', !empty($r['sms']) ? 'yes' : '', $r['coupon'] ?? '',
                        !empty($r['coupon']) ? $coupon_view($r)['label']['en'] : '', $r['used'] ?? '']));
            }
            exit;
        }
        out(['error' => 'unknown'], 404);
    }

    out(['error' => 'unknown'], 404);
} catch (Throwable $e) {
    out(['error' => 'server'], 500);
}
