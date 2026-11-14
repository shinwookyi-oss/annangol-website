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

    out(['error' => 'unknown'], 404);
} catch (Throwable $e) {
    out(['error' => 'server'], 500);
}
