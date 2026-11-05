<?php
// POST /api/v1/launcher/report  업데이트 결과 보고
require_once __DIR__ . '/../../../launcher_api.php';

$in = client_input();
$app = client_app($in);
$pcId = client_pc_id($in);
$row = touch_install($app, $pcId, $in);

$result = in_str($in, 'result', 16);
if (!in_array($result, ['success', 'fail', 'uptodate', 'recovered', 'offline', 'blocked'], true)) fail('잘못된 result');
$now = now_str();
$label = in_str($in, 'toVersion', 32);
$build = intval(isset($in['toBuild']) ? $in['toBuild'] : 0);
$msg = in_str($in, 'message', 2000);

// 설치 현황 갱신 (실패해도 기존 버전은 유지되므로 버전은 런처가 보고한 현재 버전으로)
$curLabel = $result === 'success' || $result === 'uptodate' ? $label : in_str($in, 'fromVersion', 32);
$curBuild = $result === 'success' || $result === 'uptodate' ? $build : intval(isset($in['fromBuild']) ? $in['fromBuild'] : $row['build']);
db_exec('UPDATE client_install SET version_label = ?, build = ?, last_result = ?, last_message = ?' . ($result === 'success' ? ', last_update_at = ?' : '') . ' WHERE id = ?',
    $result === 'success' ? [$curLabel, $curBuild, $result, $msg, $now, $row['id']] : [$curLabel, $curBuild, $result, $msg, $row['id']]);

// '최신 상태' 확인은 매 실행마다 오므로 이력에는 남기지 않음
if ($result !== 'uptodate') {
    db_exec('INSERT INTO update_history (app_id, pc_id, pc_name, time, result, from_label, to_label, to_build, files, bytes, duration_ms, launcher_version, message) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
        $app['id'], $pcId, in_str($in, 'pcName', 100), $now, $result, in_str($in, 'fromVersion', 32), $label, $build,
        intval(isset($in['files']) ? $in['files'] : 0), intval(isset($in['bytes']) ? $in['bytes'] : 0), intval(isset($in['durationMs']) ? $in['durationMs'] : 0),
        in_str($in, 'launcherVersion', 32), $msg,
    ]);
}
json_out(['success' => true]);
