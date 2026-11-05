<?php
// 런처(Updater.exe) 가 호출하는 API 공통 처리
require_once __DIR__ . '/lib.php';

function client_input() {
    $raw = file_get_contents('php://input');
    $in = json_decode($raw, true);
    if (!is_array($in)) $in = $_POST;
    if (CLIENT_KEY !== '') {
        $key = isset($_SERVER['HTTP_X_CLIENT_KEY']) ? $_SERVER['HTTP_X_CLIENT_KEY'] : (isset($in['clientKey']) ? $in['clientKey'] : '');
        if (!hash_equals(CLIENT_KEY, (string)$key)) fail('인증 실패 (ClientKey 확인)', 401);
    }
    return $in;
}

function in_str($in, $k, $max = 255) {
    $v = isset($in[$k]) ? trim((string)$in[$k]) : '';
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

function client_app($in) {
    $id = in_str($in, 'solutionId', 40);
    $app = load_app($id);
    if (!$app) fail('등록되지 않은 솔루션입니다: ' . $id, 404);
    return $app;
}

function client_pc_id($in) {
    $pc = in_str($in, 'pcId', 64);
    if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $pc)) fail('잘못된 PC ID', 400);
    return $pc;
}

function client_ip() { return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''; }

// PC 와 설치 정보 등록/갱신 후 설치 행 반환
function touch_install($app, $pcId, $in) {
    $now = now_str();
    $pcName = in_str($in, 'pcName', 100);
    $pdo = db();
    if (db_one('SELECT pc_id FROM client_pc WHERE pc_id = ?', [$pcId])) {
        db_exec('UPDATE client_pc SET pc_name = ?, last_seen = ?, last_ip = ?, os_user = ? WHERE pc_id = ?', [$pcName, $now, client_ip(), in_str($in, 'osUser', 100), $pcId]);
    } else {
        db_exec('INSERT INTO client_pc (pc_id, pc_name, first_seen, last_seen, last_ip, os_user) VALUES (?,?,?,?,?,?)', [$pcId, $pcName, $now, $now, client_ip(), in_str($in, 'osUser', 100)]);
    }
    $row = db_one('SELECT * FROM client_install WHERE app_id = ? AND pc_id = ?', [$app['id'], $pcId]);
    if (!$row) {
        db_exec('INSERT INTO client_install (app_id, pc_id, version_label, build, launcher_version, install_path, first_seen, last_check, last_result, blocked) VALUES (?,?,?,?,?,?,?,?,?,0)',
            [$app['id'], $pcId, in_str($in, 'currentVersion', 32), intval(isset($in['currentBuild']) ? $in['currentBuild'] : 0), in_str($in, 'launcherVersion', 32), in_str($in, 'installPath', 255), $now, $now, 'checking']);
        $row = db_one('SELECT * FROM client_install WHERE app_id = ? AND pc_id = ?', [$app['id'], $pcId]);
    } else {
        db_exec('UPDATE client_install SET last_check = ?, launcher_version = ?, install_path = ? WHERE id = ?',
            [$now, in_str($in, 'launcherVersion', 32), in_str($in, 'installPath', 255), $row['id']]);
    }
    return $row;
}
