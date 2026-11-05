<?php
// ===== Google OTP (TOTP, RFC 6238) - 외부 라이브러리 없이 구현 =====
// Google Authenticator, Microsoft Authenticator 등 TOTP 앱과 호환 (SHA1, 6자리, 30초)
// 등록 정보: data/otp.json (data 폴더는 외부 접근 차단)

define('OTP_FILE', DATA_DIR . '/otp.json');
define('GUARD_FILE', DATA_DIR . '/login_guard.json');
define('LOGIN_MAX_FAIL', 5);     // 이 횟수만큼 틀리면
define('LOGIN_LOCK_SEC', 600);   // 이 시간(초) 동안 해당 IP 로그인 잠금

function otp_mode() {
    $m = defined('OTP_MODE') ? OTP_MODE : 'password+otp';
    return in_array($m, ['off', 'password+otp', 'otp'], true) ? $m : 'password+otp';
}

function base32_encode($bin) {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alpha[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode($s) {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[\s=]/', '', $s));
    $bits = '';
    for ($i = 0; $i < strlen($s); $i++) {
        $v = strpos($alpha, $s[$i]);
        if ($v === false) return false;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function otp_new_secret() { return base32_encode(random_bytes(20)); } // 160bit, 32글자

function totp_code($secret, $step) {
    $key = base32_decode($secret);
    $msg = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $o = ord($hash[19]) & 0x0F;
    $n = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

// 맞으면 사용한 시간칸(step) 번호, 틀리면 false. 앞뒤 30초 오차 허용
function totp_verify($secret, $code, $lastStep = -1) {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6) return false;
    $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $step = $now + $d;
        if ($step <= $lastStep) continue; // 이미 쓴 코드 재사용 방지
        if (hash_equals(totp_code($secret, $step), $code)) return $step;
    }
    return false;
}

function otp_uri($secret, $account) {
    $issuer = SITE_TITLE;
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

// ---------- 등록 정보 저장 ----------
function otp_load() {
    if (!is_file(OTP_FILE)) return null;
    $d = json_decode((string)@file_get_contents(OTP_FILE), true);
    return (is_array($d) && !empty($d['secret'])) ? $d : null;
}
function otp_save($d) {
    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    return @file_put_contents(OTP_FILE, json_encode($d, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function otp_registered() { return otp_load() !== null; }
// 실제로 OTP 를 요구하는지 (등록 전에는 비밀번호만으로 로그인해서 등록하도록)
function otp_required() { return otp_mode() !== 'off' && otp_registered(); }

// 로그인 OTP 확인 + 재사용 방지 기록
function otp_check_login($code) {
    $d = otp_load();
    if (!$d) return false;
    $step = totp_verify($d['secret'], $code, isset($d['last_step']) ? (int)$d['last_step'] : -1);
    if ($step === false) return false;
    $d['last_step'] = $step;
    $d['last_login'] = now_str();
    otp_save($d);
    return true;
}

// ---------- 로그인 실패 잠금 (IP 기준) ----------
function guard_ip() { return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-'; }
function guard_load() {
    $g = is_file(GUARD_FILE) ? json_decode((string)@file_get_contents(GUARD_FILE), true) : [];
    if (!is_array($g)) $g = [];
    $t = time();
    foreach ($g as $ip => $v) if ($t - $v['first'] > LOGIN_LOCK_SEC) unset($g[$ip]);
    return $g;
}
function guard_locked_sec() {
    $g = guard_load(); $ip = guard_ip();
    if (empty($g[$ip]) || $g[$ip]['count'] < LOGIN_MAX_FAIL) return 0;
    return max(0, LOGIN_LOCK_SEC - (time() - $g[$ip]['first']));
}
function guard_fail() {
    $g = guard_load(); $ip = guard_ip();
    if (empty($g[$ip])) $g[$ip] = ['count' => 0, 'first' => time()];
    $g[$ip]['count']++;
    @file_put_contents(GUARD_FILE, json_encode($g), LOCK_EX);
}
function guard_clear() {
    $g = guard_load(); unset($g[guard_ip()]);
    @file_put_contents(GUARD_FILE, json_encode($g), LOCK_EX);
}
