<?php
// ===== 배포 전자서명 (Manifest 서명) =====
// - 서명 키(RSA 2048)는 관리자 PC 의 브라우저에서 만들고, 비밀 키는 암호로 잠근 파일로만 보관합니다.
//   서버에는 공개 키만 저장 (data/signkey.json). 비밀 키는 서버로 전송되지 않습니다.
// - 배포/롤백 등 PC 가 받는 파일 목록이 바뀌는 작업은 서버가 "서명할 내용(payload)"을 돌려주고,
//   브라우저가 키 파일로 서명해 다시 보내야 반영됩니다.
// - 각 PC 의 Updater.exe 는 updater.ini 의 SignKey(공개 키)로 서명을 확인하고, 맞지 않으면 설치하지 않습니다.
//   그래서 NAS 가 해킹되거나 관리자 비밀번호·OTP 가 유출돼도 키 파일 + 키 암호 없이는 배포할 수 없습니다.

define('SIGNKEY_FILE', DATA_DIR . '/signkey.json');

function sign_available() { return function_exists('openssl_verify') && function_exists('openssl_pkey_get_public'); }

function sign_key() {
    if (!is_file(SIGNKEY_FILE)) return null;
    $k = json_decode((string)@file_get_contents(SIGNKEY_FILE), true);
    return (is_array($k) && !empty($k['pem']) && !empty($k['n'])) ? $k : null;
}
function signing_enabled() { return sign_key() !== null; }

// 브라우저가 보낸 공개 키(SPKI, base64) 검사 후 저장용 정보로 변환
function sign_parse_spki($b64) {
    if (!sign_available()) fail('PHP openssl 확장이 꺼져 있어 전자서명을 쓸 수 없습니다. (Web Station › PHP 설정 › 확장에서 openssl 켜기)');
    $der = base64_decode((string)$b64, true);
    if ($der === false || strlen($der) < 200 || strlen($der) > 2000) fail('공개 키 형식이 잘못되었습니다.');
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $pk = openssl_pkey_get_public($pem);
    if (!$pk) fail('공개 키를 읽을 수 없습니다.');
    $d = openssl_pkey_get_details($pk);
    if (empty($d['rsa']['n']) || $d['bits'] < 2048) fail('RSA 2048비트 이상 키만 사용할 수 있습니다.');
    return [
        'pem' => $pem,
        'n' => base64_encode(ltrim($d['rsa']['n'], "\0")),
        'e' => base64_encode(ltrim($d['rsa']['e'], "\0")),
        'bits' => $d['bits'],
        'fingerprint' => sign_fingerprint($der),
        'created' => now_str(),
    ];
}
function sign_fingerprint($der) { return implode(':', str_split(strtoupper(substr(hash('sha256', $der), 0, 16)), 4)); }
function sign_key_save($k) { return @file_put_contents(SIGNKEY_FILE, json_encode($k, JSON_PRETTY_PRINT), LOCK_EX) !== false; }

// updater.ini 에 넣을 공개 키 한 줄 값: base64(modulus).base64(exponent)
function sign_ini_value() { $k = sign_key(); return $k ? $k['n'] . '.' . $k['e'] : ''; }

// PC 가 실제로 따르는 내용만 서명 (공지·활성 같은 표시용 설정은 서명 없이 바꿀 수 있게 제외)
function signed_payload($s) {
    $files = [];
    foreach ($s['files'] as $p => $f) $files[] = ['path' => (string)$p, 'size' => intval($f['size']), 'sha256' => $f['sha256']];
    return json_encode([
        'app'        => $s['id'],
        'build'      => intval($s['version']),
        'version'    => $s['versionLabel'] ?: (string)$s['version'],
        'launch'     => $s['launch'],
        'launchArgs' => $s['launchArgs'],
        'files'      => $files,
        'delete'     => array_values($s['delete']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function sign_verify($payload, $sigB64) {
    $k = sign_key();
    if (!$k || !sign_available()) return false;
    $sig = base64_decode((string)$sigB64, true);
    if ($sig === false || $sig === '') return false;
    return openssl_verify($payload, $sig, $k['pem'], OPENSSL_ALGO_SHA256) === 1;
}

// save_app 에서 호출: 서명이 없거나 틀리면 payload 를 돌려주고 요청을 끝냄 (아무것도 저장하지 않음)
function sign_require(&$s) {
    $payload = signed_payload($s);
    $sig = isset($GLOBALS['SIGN_OVERRIDE']) ? (string)$GLOBALS['SIGN_OVERRIDE'] : (isset($_POST['signature']) ? trim((string)$_POST['signature']) : '');
    if ($sig === '') json_out(['ok' => false, 'success' => false, 'needSign' => true, 'payload' => $payload, 'error' => '전자서명이 필요합니다.'], 409);
    if (!sign_verify($payload, $sig)) {
        audit_log('sign_fail', ['app' => $s['id'], 'build' => intval($s['version'])]);
        json_out(['ok' => false, 'success' => false, 'needSign' => true, 'payload' => $payload,
                  'error' => '서명이 맞지 않습니다. 등록된 키의 파일이 맞는지 확인하세요. (그 사이 다른 변경이 있었다면 새로고침 후 다시 시도)'], 409);
    }
    $s['signed'] = $payload;
    $s['signature'] = $sig;
}

// 서명을 쓰면 배포 계열 작업은 서명으로 확인하고, 아니면 OTP 재인증
function require_publish_auth($what) {
    if (!signing_enabled()) require_stepup($what);
}

// 현재 운영 버전이 지금 등록된 키로 올바르게 서명돼 있는지
function sign_state_ok($s) {
    return !empty($s['signature']) && !empty($s['signed'])
        && strpos($s['signed'], '"build":' . intval($s['version']) . ',') !== false
        && sign_verify($s['signed'], $s['signature']);
}

// ---------- 런처(Updater.exe) 일괄 업그레이드 ----------
// 서버의 tools/Updater.exe 와 (서명 사용 시) 공개 키 파일 updater.signkey 를 솔루션 파일 목록에 넣어 배포.
// 기존 PC 의 런처(2.0.x)도 Manifest 에 있는 Updater.exe 를 받아 자기 자신을 교체할 수 있음.
define('LAUNCHER_SRC', APP_ROOT . '/tools/Updater.exe');
define('SIGNKEY_PC_FILE', 'updater.signkey');

function blob_put_bytes($bytes) {
    $sha = hash('sha256', $bytes);
    $blob = FILES_DIR . '/' . $sha;
    if (!is_file($blob) && @file_put_contents($blob, $bytes) === false) fail('files 폴더에 저장 실패 (쓰기 권한 확인)');
    @touch($blob); @chmod($blob, 0644);
    return ['sha256' => $sha, 'size' => strlen($bytes)];
}
function launcher_files() {
    if (!is_file(LAUNCHER_SRC)) fail('서버에 tools/Updater.exe 가 없습니다.');
    $out = ['Updater.exe' => blob_put_bytes(file_get_contents(LAUNCHER_SRC))];
    if (signing_enabled()) $out[SIGNKEY_PC_FILE] = blob_put_bytes("; 배포 전자서명 공개 키 (관리 사이트에서 자동 배포, 지우지 마세요)\r\nSignKey=" . sign_ini_value() . "\r\n");
    return $out;
}
// 솔루션 상태에 런처 파일을 반영한 결과 [새 상태, change] (바뀐 게 없으면 null)
function launcher_upgrade_plan($s, $lf) {
    $added = []; $updated = [];
    foreach ($lf as $name => $f) {
        $path = $name;
        foreach (array_keys($s['files']) as $k) if (strcasecmp($k, $name) === 0) { $path = $k; break; }
        $old = isset($s['files'][$path]) ? $s['files'][$path] : null;
        if ($old && $old['sha256'] === $f['sha256']) continue;
        $s['files'][$path] = $f + ['uploaded' => now_str()];
        $s['delete'] = array_values(array_filter($s['delete'], function ($d) use ($path) { return strcasecmp($d, $path) !== 0; }));
        if ($old) $updated[] = $path; else $added[] = $path;
    }
    if (!$added && !$updated) return null;
    return [$s, ['type' => 'deploy', 'label' => suggest_label($s), 'memo' => '런처 업그레이드' . (isset($lf[SIGNKEY_PC_FILE]) ? ' (전자서명 확인 적용)' : ''), 'added' => $added, 'updated' => $updated, 'deleted' => []]];
}
