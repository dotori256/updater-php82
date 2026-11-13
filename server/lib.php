<?php
require_once __DIR__ . '/config.php';

define('APP_ROOT', __DIR__);
define('FILES_DIR', __DIR__ . '/files');          // 파일 본문 저장소 (SHA-256 이름, 모든 솔루션 공용)
define('MANIFEST_DIR', __DIR__ . '/manifests');   // 공개 Manifest (솔루션ID.json)
define('DATA_DIR', __DIR__ . '/data');            // 내부 데이터 (외부 접근 차단)
define('APPS_DIR', DATA_DIR . '/apps');
define('HISTORY_KEEP', 50);                       // 솔루션별 보관할 배포 이력 수 (롤백 가능 범위)
define('SERVER_VERSION', '1.1.0');

date_default_timezone_set(defined('TIMEZONE') ? TIMEZONE : 'Asia/Seoul');
require_once __DIR__ . '/otp.php';
require_once __DIR__ . '/sign.php';

// ---------- 공통 ----------
function admin_boot() {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (PHP_VERSION_ID >= 70300) session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    else session_set_cookie_params(0, '/', '', $https, true);
    session_start();
}
function is_logged_in() { return !empty($_SESSION['admin']); }

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail($msg, $code = 400) { json_out(['ok' => false, 'success' => false, 'error' => $msg], $code); }

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_size($b) {
    if ($b >= 1073741824) return number_format($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576) return number_format($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return number_format($b / 1024, 1) . ' KB';
    return $b . ' B';
}

function now_str() { return date('Y-m-d H:i:s'); }

// bin 폴더 기준 상대경로 정리. 위험한 경로면 null
function clean_path($p) {
    $p = str_replace('\\', '/', trim((string)$p));
    $parts = [];
    foreach (explode('/', $p) as $seg) {
        $seg = trim($seg);
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..' || strpos($seg, ':') !== false) return null;
        if (preg_match('/[<>"|?*\x00-\x1f]/', $seg)) return null;
        $parts[] = $seg;
    }
    if (!$parts || strcasecmp($parts[0], '.update') === 0) return null; // 런처 작업 폴더는 배포 대상 아님
    return implode('/', $parts);
}

function valid_app_id($id) { return is_string($id) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,39}$/', $id); }

// 시맨틱 버전 (Major.Minor.Patch)
function valid_semver($v) { return is_string($v) && preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}$/', $v); }
function next_patch($v) {
    if (!valid_semver($v)) return '1.0.0';
    $p = explode('.', $v);
    $p[2] = intval($p[2]) + 1;
    return implode('.', $p);
}
function semver_cmp($a, $b) {
    return version_compare(valid_semver($a) ? $a : '0.0.0', valid_semver($b) ? $b : '0.0.0');
}

// 지금까지 쓴 버전 중 가장 높은 버전 (롤백 후에도 버전 번호가 겹치지 않도록)
function max_label($s) {
    $max = valid_semver($s['versionLabel']) ? $s['versionLabel'] : '';
    foreach (load_history($s['id']) as $hst) if (!empty($hst['label']) && semver_cmp($hst['label'], $max ?: '0.0.0') > 0) $max = $hst['label'];
    return $max;
}
function suggest_label($s) { $m = max_label($s); return $m === '' ? '1.0.0' : next_patch($m); }

function read_json($file, $def) {
    if (!is_file($file)) return $def;
    $v = json_decode(file_get_contents($file), true);
    return is_array($v) ? $v : $def;
}

function write_json($file, $data) {
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception('폴더 생성 실패: ' . basename($dir) . ' (쓰기 권한 확인)');
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new Exception('저장 실패: ' . basename($file) . ' (쓰기 권한 확인)');
    if (!rename($tmp, $file)) { @unlink($tmp); throw new Exception('저장 실패: ' . basename($file)); }
}

// 동시 요청이 상태 파일을 덮어쓰지 않도록 전체 잠금
function with_lock($fn) {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $fp = fopen(DATA_DIR . '/.lock', 'c');
    flock($fp, LOCK_EX);
    try { return $fn(); }
    finally { flock($fp, LOCK_UN); fclose($fp); }
}

// ---------- DB (PC 현황 / 업데이트 이력) ----------
function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(DB_DSN, DB_USER ?: null, DB_PASS ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if (!$mysql) { $pdo->exec('PRAGMA journal_mode=WAL'); $pdo->exec('PRAGMA busy_timeout=5000'); }
    $auto = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $txt = $mysql ? 'VARCHAR(255)' : 'TEXT';
    $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_pc (
        pc_id VARCHAR(64) PRIMARY KEY, pc_name $txt, first_seen VARCHAR(19), last_seen VARCHAR(19), last_ip VARCHAR(64), os_user $txt
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS client_install (
        id $auto, app_id VARCHAR(40) NOT NULL, pc_id VARCHAR(64) NOT NULL,
        version_label VARCHAR(32), build INT DEFAULT 0, launcher_version VARCHAR(32), install_path $txt,
        first_seen VARCHAR(19), last_check VARCHAR(19), last_result VARCHAR(16), last_message TEXT, last_update_at VARCHAR(19),
        blocked INT DEFAULT 0, memo $txt,
        UNIQUE (app_id, pc_id)
    )$engine");
    $pdo->exec("CREATE TABLE IF NOT EXISTS update_history (
        id $auto, app_id VARCHAR(40) NOT NULL, pc_id VARCHAR(64) NOT NULL, pc_name $txt, time VARCHAR(19),
        result VARCHAR(16), from_label VARCHAR(32), to_label VARCHAR(32), to_build INT, files INT, bytes BIGINT, duration_ms INT,
        launcher_version VARCHAR(32), message TEXT
    )$engine");
    try { $pdo->exec('CREATE INDEX idx_hist_app_time ON update_history (app_id, time)'); } catch (Exception $e) {}
    try { $pdo->exec('CREATE INDEX idx_hist_pc ON update_history (pc_id)'); } catch (Exception $e) {}
    return $pdo;
}

function db_one($sql, $args = []) { $st = db()->prepare($sql); $st->execute($args); $r = $st->fetch(); return $r ?: null; }
function db_all($sql, $args = []) { $st = db()->prepare($sql); $st->execute($args); return $st->fetchAll(); }
function db_exec($sql, $args = []) { $st = db()->prepare($sql); $st->execute($args); return $st->rowCount(); }

// 솔루션별 설치 현황 집계
function install_stats($app) {
    $rows = db_all('SELECT build, last_result, last_check, blocked FROM client_install WHERE app_id = ?', [$app['id']]);
    $cut = date('Y-m-d H:i:s', time() - INACTIVE_DAYS * 86400);
    $st = ['total' => 0, 'latest' => 0, 'old' => 0, 'failed' => 0, 'inactive' => 0, 'blocked' => 0];
    foreach ($rows as $r) {
        $st['total']++;
        if (intval($r['build']) >= intval($app['version'])) $st['latest']++; else $st['old']++;
        if ($r['last_result'] === 'fail') $st['failed']++;
        if ($r['last_check'] < $cut) $st['inactive']++;
        if ($r['blocked']) $st['blocked']++;
    }
    return $st;
}

// ---------- 솔루션(앱) ----------
function app_defaults() {
    return ['name' => '', 'code' => '', 'memo' => '', 'active' => true, 'notice' => '', 'offlineRun' => true,
            'version' => 0, 'versionLabel' => '', 'releaseNote' => '', 'launch' => '', 'launchArgs' => '',
            'files' => [], 'delete' => [], 'updated' => '', 'created' => ''];
}

function list_apps() {
    $apps = [];
    foreach (glob(APPS_DIR . '/*/state.json') ?: [] as $f) {
        $s = read_json($f, null);
        if ($s && isset($s['id'])) $apps[$s['id']] = $s + app_defaults();
    }
    uksort($apps, 'strnatcasecmp');
    return $apps;
}

function app_state_file($id) { return APPS_DIR . '/' . $id . '/state.json'; }
function app_history_file($id) { return APPS_DIR . '/' . $id . '/history.json'; }

function load_app($id) {
    if (!valid_app_id($id)) return null;
    $s = read_json(app_state_file($id), null);
    if (!$s) {
        // 대소문자만 다른 ID 도 찾아줌 (NAS 리눅스 파일시스템은 대소문자 구분)
        foreach (glob(APPS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir)
            if (strcasecmp(basename($dir), $id) === 0) { $s = read_json($dir . '/state.json', null); break; }
    }
    if (!$s) return null;
    $s += app_defaults();
    if ($s['name'] === '') $s['name'] = $s['id'];
    return $s;
}

function load_history($id) { return read_json(app_history_file($id), []); }

// 변경 기록용 메타 반영 (빌드 번호 증가, 정렬) - 저장하지 않음. 서명할 내용을 미리 계산할 때도 사용
function bump_meta(&$s, $change) {
    if ($change !== null) {
        $s['version'] = intval($s['version']) + 1;     // 빌드 번호 (내부 비교용, 항상 증가)
        $s['updated'] = now_str();
        if (!empty($change['label'])) $s['versionLabel'] = $change['label'];
        if (isset($change['memo']) && in_array($change['type'], ['deploy', 'rollback'])) $s['releaseNote'] = $change['memo'];
    }
    uksort($s['files'], 'strnatcasecmp');
    $s['delete'] = array_values(array_unique($s['delete']));
}

// 상태 저장 → 공개 Manifest 재생성 → 배포 이력 기록
// $change: ['type' => 'deploy|rollback|settings|create', 'memo', 'label', 'added', 'updated', 'deleted']
function save_app($s, $change = null) {
    bump_meta($s, $change);
    // 전자서명 사용 중이면 PC 가 받는 내용이 바뀌는 변경은 서명이 있어야 저장 (없으면 여기서 payload 를 돌려주고 끝)
    if ($change !== null && signing_enabled()) sign_require($s);
    write_json(app_state_file($s['id']), $s);
    write_manifest($s);

    if ($change !== null) {
        $hist = load_history($s['id']);
        $snap = [];
        foreach ($s['files'] as $p => $f) $snap[$p] = ['size' => $f['size'], 'sha256' => $f['sha256']];
        array_unshift($hist, [
            'version' => $s['version'],
            'label'   => $s['versionLabel'],
            'time'    => $s['updated'],
            'type'    => $change['type'],
            'memo'    => isset($change['memo']) ? $change['memo'] : '',
            'launch'  => $s['launch'],
            'launchArgs' => $s['launchArgs'],
            'added'   => isset($change['added']) ? $change['added'] : [],
            'updated' => isset($change['updated']) ? $change['updated'] : [],
            'deleted' => isset($change['deleted']) ? $change['deleted'] : [],
            'files'   => $snap,
        ]);
        write_json(app_history_file($s['id']), array_slice($hist, 0, HISTORY_KEEP));
        gc_blobs();
    }
    return $s;
}

function write_manifest($s) {
    $files = [];
    foreach ($s['files'] as $path => $f) $files[] = ['path' => $path, 'size' => $f['size'], 'hash' => $f['sha256'], 'sha256' => $f['sha256']];
    $m = [
        'solutionId'  => $s['id'],
        'name'        => $s['name'],
        'version'     => $s['versionLabel'] ?: (string)$s['version'],
        'build'       => intval($s['version']),
        'channel'     => 'PRODUCTION',
        'updated'     => $s['updated'],
        'active'      => (bool)$s['active'],
        'offlineRun'  => (bool)$s['offlineRun'],
        'notice'      => $s['notice'],
        'releaseNote' => $s['releaseNote'],
        'launch'      => $s['launch'],
        'launchArgs'  => $s['launchArgs'],
        'files'       => $files,
        'delete'      => $s['delete'],
    ];
    // 서명된 내용: Updater 에 SignKey 가 있으면 위 값 대신 이것만 믿고 따름
    if (!empty($s['signed']) && !empty($s['signature']) && strpos($s['signed'], '"build":' . intval($s['version']) . ',') !== false) {
        $m['signed'] = $s['signed'];
        $m['signature'] = $s['signature'];
    }
    write_json(MANIFEST_DIR . '/' . $s['id'] . '.json', $m);
}

// 어떤 솔루션의 현재 상태나 보관 이력에도 없는 파일 본문 삭제
// (업로드 직후 아직 배포 확정 전인 파일을 지우지 않도록 하루 지난 것만)
function gc_blobs() {
    $used = [];
    foreach (glob(APPS_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $s = read_json($dir . '/state.json', []);
        if (!empty($s['files'])) foreach ($s['files'] as $f) $used[$f['sha256']] = true;
        foreach (read_json($dir . '/history.json', []) as $hst)
            if (!empty($hst['files'])) foreach ($hst['files'] as $f) $used[$f['sha256']] = true;
    }
    $cutoff = time() - 86400;
    foreach (glob(FILES_DIR . '/*') ?: [] as $blob) {
        $name = basename($blob);
        if (preg_match('/^[0-9a-f]{64}$/', $name) && !isset($used[$name]) && filemtime($blob) < $cutoff) @unlink($blob);
    }
}

function base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // api/v1/launcher/*.php 에서 불려도 사이트 루트 주소를 돌려줌
    $dir = preg_replace('#/api/v1/launcher$#', '', $dir);
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim($dir, '/') . '/';
}

function updater_ini($app) {
    return "; 런처 설정 - Updater.exe 와 같은 폴더(bin)에 두세요.\r\n"
        . "ServerUrl=" . base_url() . "\r\n"
        . "App=" . $app['id'] . "\r\n"
        . (CLIENT_KEY !== '' ? "ClientKey=" . CLIENT_KEY . "\r\n" : "")
        . "; 실행 파일/인자 (비워두면 관리 사이트 설정 사용)\r\n"
        . "LaunchExe=\r\n"
        . "LaunchArgs=\r\n"
        . "; 업데이트 후 자동 실행(1) 또는 실행 버튼 대기(0)\r\n"
        . "AutoStart=1\r\n"
        . "TimeoutSec=15\r\n"
        . "ShowWindow=1\r\n"
        . "DeleteRemovedFiles=1\r\n"
        . (signing_enabled() ? "; 배포 전자서명 확인용 공개 키 (서명이 맞지 않는 업데이트는 설치하지 않음)\r\nSignKey=" . sign_ini_value() . "\r\n" : "");
}

// 문자열 자르기 (mbstring 없는 서버 대비)
function cut($s, $n) {
    $s = (string)$s;
    if (function_exists('mb_strimwidth')) return mb_strimwidth($s, 0, $n, '…', 'UTF-8');
    return strlen($s) > $n ? substr($s, 0, $n) . '…' : $s;
}
