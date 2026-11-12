<?php
require_once __DIR__ . '/lib.php';
admin_boot();

if (!is_logged_in()) fail('로그인이 필요합니다. 새로고침 하세요.', 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only', 405);
if (!hash_equals(csrf_token(), isset($_POST['csrf']) ? (string)$_POST['csrf'] : '')) fail('보안 토큰이 맞지 않습니다. 새로고침 후 다시 시도하세요.', 403);

function post($k, $def = '') { return isset($_POST[$k]) ? $_POST[$k] : $def; }
function post_json($k) { $v = json_decode(post($k, '[]'), true); return is_array($v) ? $v : []; }

function need_app() {
    $s = load_app(post('app'));
    if (!$s) fail('솔루션을 찾을 수 없습니다.');
    return $s;
}

try {
    switch (post('action')) {

    // ----- 솔루션 추가 -----
    case 'create_app':
        $id = trim(post('id'));
        if (!valid_app_id($id)) fail('솔루션 ID는 영문/숫자/-/_ 로 40자 이내여야 합니다.');
        $name = trim(post('name')) ?: $id;
        $copyFrom = trim(post('copyFrom'));
        if ($copyFrom !== '') require_stepup('create_app ' . $id . ' <- ' . $copyFrom); // 파일 복사 = 배포
        with_lock(function () use ($id, $name, $copyFrom) {
            foreach (list_apps() as $a) if (strcasecmp($a['id'], $id) === 0) fail('이미 있는 ID입니다: ' . $a['id']);
            $s = ['id' => $id, 'name' => $name, 'code' => trim(post('code')), 'memo' => trim(post('memo')), 'created' => now_str()] + app_defaults();
            $change = ['type' => 'create', 'memo' => '솔루션 생성'];
            if ($copyFrom !== '' && ($src = load_app($copyFrom))) {
                $s['files'] = $src['files'];
                $s['launch'] = $src['launch'];
                $s['launchArgs'] = $src['launchArgs'];
                $change['label'] = '1.0.0';
                $change['memo'] = $src['id'] . ' 에서 파일 복사하여 생성';
                $change['added'] = array_keys($src['files']);
            }
            save_app($s, $change);
        });
        audit_log('create_app', ['app' => $id, 'copyFrom' => $copyFrom]);
        json_out(['ok' => true, 'id' => $id]);

    // ----- 솔루션 정보/실행 설정 -----
    case 'update_app':
        $launch = trim(post('launch'));
        if ($launch !== '' && ($launch = clean_path($launch)) === null) fail('실행 파일 경로가 잘못되었습니다.');
        // 실행 파일/인자를 바꾸면 PC 에서 다른 프로그램이 실행되므로 재인증
        $cur = need_app();
        if ($cur['launch'] !== $launch || $cur['launchArgs'] !== trim(post('launchArgs'))) {
            require_stepup('update_app ' . $cur['id']);
            audit_log('launch_change', ['app' => $cur['id'], 'from' => trim($cur['launch'] . ' ' . $cur['launchArgs']), 'to' => trim($launch . ' ' . trim(post('launchArgs')))]);
        }
        with_lock(function () use ($launch) {
            $s = need_app();
            $runChanged = $s['launch'] !== $launch || $s['launchArgs'] !== trim(post('launchArgs'));
            $s['name'] = trim(post('name')) ?: $s['id'];
            $s['code'] = trim(post('code'));
            $s['memo'] = trim(post('memo'));
            $s['notice'] = trim(post('notice'));
            $s['active'] = post('active') === '1';
            $s['offlineRun'] = post('offlineRun') === '1';
            $s['launch'] = $launch;
            $s['launchArgs'] = trim(post('launchArgs'));
            save_app($s, $runChanged ? ['type' => 'settings', 'memo' => '실행 설정 변경: ' . ($launch ?: '(없음)') . ($s['launchArgs'] ? ' ' . $s['launchArgs'] : '')] : null);
        });
        json_out(['ok' => true]);

    // ----- 솔루션 삭제 -----
    case 'delete_app':
        $s = need_app();
        if (post('confirm') !== $s['id']) fail('확인용 ID가 일치하지 않습니다.');
        require_stepup('delete_app ' . $s['id']);
        audit_log('delete_app', ['app' => $s['id']]);
        with_lock(function () use ($s) {
            $dir = APPS_DIR . '/' . $s['id'];
            foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
            @unlink(MANIFEST_DIR . '/' . $s['id'] . '.json');
            try { db_exec('DELETE FROM client_install WHERE app_id = ?', [$s['id']]); db_exec('DELETE FROM update_history WHERE app_id = ?', [$s['id']]); } catch (Exception $e) {}
            gc_blobs();
        });
        json_out(['ok' => true]);

    // ----- 파일 본문 업로드 (배포 확정 전 단계) -----
    case 'upload':
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $err = empty($_FILES['file']) ? -1 : $_FILES['file']['error'];
            fail(($err == UPLOAD_ERR_INI_SIZE || $err == UPLOAD_ERR_FORM_SIZE || $err == -1)
                ? '파일이 너무 큽니다. (서버 업로드 한도 ' . ini_get('upload_max_filesize') . ', php.ini 의 upload_max_filesize / post_max_size)'
                : '업로드 오류 코드 ' . $err);
        }
        $tmp = $_FILES['file']['tmp_name'];
        $sha = hash_file('sha256', $tmp);
        $size = filesize($tmp);
        $blob = FILES_DIR . '/' . $sha;
        if (is_file($blob)) @touch($blob);
        elseif (!move_uploaded_file($tmp, $blob)) fail('files 폴더에 저장 실패 (쓰기 권한 확인)');
        @chmod($blob, 0644);
        json_out(['ok' => true, 'sha256' => $sha, 'size' => $size]);

    // ----- 배포 확정: 올린 파일 반영 + 삭제를 한 버전으로 -----
    // items: [{path, sha256, size}], deletes: [path]
    case 'deploy':
        $items = post_json('items');
        $deletes = post_json('deletes');
        $removeOnPc = post('removeOnPc') !== '';
        $memo = trim(post('memo'));
        $label = trim(post('label'));
        if ($label !== '' && !valid_semver($label)) fail('버전은 1.2.3 처럼 Major.Minor.Patch 형식으로 입력하세요.');
        if (!$items && !$deletes) fail('배포할 파일이 없습니다.');
        $pre = need_app(); $preMax = max_label($pre); // OTP 코드를 쓰기 전에 미리 확인
        if ($label !== '' && $preMax !== '' && semver_cmp($label, $preMax) <= 0) fail('새 버전(' . $label . ')은 지금까지 배포한 가장 높은 버전(' . $preMax . ')보다 높아야 합니다.');
        require_stepup('deploy ' . post('app'));
        $result = with_lock(function () use ($items, $deletes, $removeOnPc, $memo, $label) {
            $s = need_app();
            $maxLabel = max_label($s);
            if ($label === '') $label = suggest_label($s);
            elseif ($maxLabel !== '' && semver_cmp($label, $maxLabel) <= 0) fail('새 버전(' . $label . ')은 지금까지 배포한 가장 높은 버전(' . $maxLabel . ')보다 높아야 합니다.');
            $added = []; $updated = []; $deleted = []; $same = [];
            foreach ($items as $it) {
                $path = clean_path(isset($it['path']) ? $it['path'] : '');
                $sha = isset($it['sha256']) ? strtolower($it['sha256']) : '';
                if ($path === null || !preg_match('/^[0-9a-f]{64}$/', $sha)) fail('잘못된 항목: ' . (isset($it['path']) ? $it['path'] : ''));
                $blob = FILES_DIR . '/' . $sha;
                if (!is_file($blob)) fail('업로드된 파일을 찾을 수 없습니다: ' . $path . ' (다시 올려주세요)');
                $size = filesize($blob);
                // 대소문자만 다른 기존 경로가 있으면 그 경로를 교체 (Windows 는 대소문자 구분 안 함)
                foreach (array_keys($s['files']) as $k) if ($k !== $path && strcasecmp($k, $path) === 0) { $path = $k; break; }
                $old = isset($s['files'][$path]) ? $s['files'][$path] : null;
                if ($old && $old['sha256'] === $sha) { $same[] = $path; continue; }
                $s['files'][$path] = ['size' => $size, 'sha256' => $sha, 'uploaded' => now_str()];
                if ($old) $updated[] = $path; else $added[] = $path;
                $s['delete'] = array_values(array_filter($s['delete'], function ($d) use ($path) { return strcasecmp($d, $path) !== 0; }));
            }
            foreach ($deletes as $p) {
                $p = clean_path($p);
                if ($p === null || !isset($s['files'][$p])) continue;
                unset($s['files'][$p]);
                $deleted[] = $p;
                if ($removeOnPc) $s['delete'][] = $p;
            }
            if ($s['launch'] === '') {
                foreach ($added as $p) if (preg_match('/\.exe$/i', $p) && !preg_match('/updater\.exe$/i', $p) && strpos($p, '/') === false) { $s['launch'] = $p; break; }
            }
            if (!$added && !$updated && !$deleted) return ['changed' => false, 'same' => count($same)];
            $s = save_app($s, ['type' => 'deploy', 'memo' => $memo, 'label' => $label, 'added' => $added, 'updated' => $updated, 'deleted' => $deleted]);
            $hashes = [];
            foreach (array_merge($added, $updated) as $p) $hashes[$p] = $s['files'][$p]['sha256'];
            audit_log('deploy', ['app' => $s['id'], 'label' => $s['versionLabel'], 'build' => $s['version'], 'memo' => $memo, 'files' => $hashes, 'deleted' => $deleted]);
            return ['changed' => true, 'version' => $s['version'], 'label' => $s['versionLabel'], 'added' => count($added), 'updated' => count($updated), 'deleted' => count($deleted), 'same' => count($same)];
        });
        json_out(['ok' => true] + $result);

    // ----- PC 삭제 예정 목록에서 빼기 -----
    case 'undelete':
        with_lock(function () {
            $s = need_app();
            $p = post('path');
            $s['delete'] = array_values(array_filter($s['delete'], function ($d) use ($p) { return $d !== $p; }));
            save_app($s);
        });
        json_out(['ok' => true]);

    // ----- 이전 버전으로 되돌리기 (새 버전으로 기록됨) -----
    case 'rollback':
        $ver = intval(post('version'));
        require_stepup('rollback ' . post('app') . ' #' . $ver);
        $result = with_lock(function () use ($ver) {
            $s = need_app();
            $snap = null; $target = null;
            foreach (load_history($s['id']) as $hst) if (intval($hst['version']) === $ver) { $snap = $hst['files']; $target = $hst; break; }
            if ($snap === null) fail('해당 버전의 이력이 없습니다.');
            foreach ($snap as $f) if (!is_file(FILES_DIR . '/' . $f['sha256'])) fail('이 버전의 파일 일부가 서버에 없어 되돌릴 수 없습니다.');
            $added = []; $updated = []; $deleted = [];
            foreach ($snap as $p => $f) {
                if (!isset($s['files'][$p])) $added[] = $p;
                elseif ($s['files'][$p]['sha256'] !== $f['sha256']) $updated[] = $p;
            }
            foreach ($s['files'] as $p => $f) if (!isset($snap[$p])) { $deleted[] = $p; $s['delete'][] = $p; }
            $now = now_str();
            $newFiles = [];
            foreach ($snap as $p => $f) {
                $keep = isset($s['files'][$p]) && $s['files'][$p]['sha256'] === $f['sha256'];
                $newFiles[$p] = $f + ['uploaded' => $keep ? $s['files'][$p]['uploaded'] : $now];
                $s['delete'] = array_values(array_filter($s['delete'], function ($d) use ($p) { return strcasecmp($d, $p) !== 0; }));
            }
            $s['files'] = $newFiles;
            if (isset($target['launch'])) { $s['launch'] = $target['launch']; $s['launchArgs'] = $target['launchArgs']; }
            $tLabel = !empty($target['label']) ? $target['label'] : '';
            audit_log('rollback', ['app' => $s['id'], 'toBuild' => $ver, 'toLabel' => $tLabel]);
            $s = save_app($s, ['type' => 'rollback', 'label' => $tLabel, 'memo' => ($tLabel ? 'v' . $tLabel : '빌드 ' . $ver) . ' 로 롤백', 'added' => $added, 'updated' => $updated, 'deleted' => $deleted]);
            return ['version' => $s['version'], 'label' => $s['versionLabel']];
        });
        json_out(['ok' => true] + $result);

    // ----- PC 별 업데이트 차단/허용, 메모, 목록에서 제거 -----
    case 'pc_update':
        $s = need_app();
        $pc = post('pc');
        if (post('remove') === '1') {
            db_exec('DELETE FROM client_install WHERE app_id = ? AND pc_id = ?', [$s['id'], $pc]);
        } else {
            db_exec('UPDATE client_install SET blocked = ?, memo = ? WHERE app_id = ? AND pc_id = ?', [post('blocked') === '1' ? 1 : 0, trim(post('memo')), $s['id'], $pc]);
        }
        json_out(['ok' => true]);

    default:
        fail('알 수 없는 요청');
    }
} catch (Exception $e) {
    fail($e->getMessage(), 500);
}
