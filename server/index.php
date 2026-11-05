<?php
require_once __DIR__ . '/lib.php';
admin_boot();
require_once __DIR__ . '/views/layout.php';

// ---------- 로그인 / 로그아웃 ----------
$loginError = '';
if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }
$needPw  = !(otp_mode() === 'otp' && otp_registered()); // OTP 전용 모드라도 OTP 등록 전에는 비밀번호 사용
$needOtp = otp_required();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $lock = guard_locked_sec();
    if ($lock > 0) {
        $loginError = '로그인 실패가 많아 잠겼습니다. ' . ceil($lock / 60) . '분 뒤에 다시 시도하세요.';
    } else {
        $ok = true;
        if ($needPw && !hash_equals(ADMIN_PASSWORD, (string)(isset($_POST['password']) ? $_POST['password'] : ''))) $ok = false;
        if ($ok && $needOtp && !otp_check_login(isset($_POST['otp']) ? $_POST['otp'] : '')) $ok = false;
        if ($ok) {
            guard_clear();
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?: './')); exit;
        }
        guard_fail();
        sleep(1);
        $loginError = $needPw && $needOtp ? '비밀번호 또는 OTP 코드가 틀렸습니다.' : ($needOtp ? 'OTP 코드가 틀렸습니다.' : '비밀번호가 틀렸습니다.');
    }
}

if (!is_logged_in()) {
    page_head(SITE_TITLE); ?>
  <div class="card login">
    <h2><?= h(SITE_TITLE) ?> 로그인</h2>
    <form method="post">
      <input type="hidden" name="login" value="1">
      <?php if ($needPw): ?><p><input type="password" name="password" placeholder="비밀번호" autofocus style="width:100%"></p><?php endif; ?>
      <?php if ($needOtp): ?><p><input type="text" name="otp" placeholder="Google OTP 6자리" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" <?= $needPw ? '' : 'autofocus' ?> style="width:100%;letter-spacing:4px"></p><?php endif; ?>
      <p><button style="width:100%">로그인</button></p>
      <?php if ($loginError): ?><p class="err"><?= h($loginError) ?></p><?php endif; ?>
      <?php if ($needPw && ADMIN_PASSWORD === 'change-me'): ?><p class="muted">초기 비밀번호는 <b>change-me</b> 입니다. config.php 에서 꼭 바꾸세요.</p><?php endif; ?>
    </form>
  </div>
</body></html>
<?php
    exit;
}

// ---------- 보안 (OTP 등록/해제) ----------
$secMsg = ''; $secErr = '';
if (isset($_GET['page']) && $_GET['page'] === 'security' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(csrf_token(), (string)(isset($_POST['csrf']) ? $_POST['csrf'] : ''))) {
        $secErr = '페이지가 만료되었습니다. 다시 시도하세요.';
    } elseif (isset($_POST['otp_enable'])) {
        $pending = isset($_SESSION['otp_pending']) ? $_SESSION['otp_pending'] : '';
        $step = $pending ? totp_verify($pending, isset($_POST['otp']) ? $_POST['otp'] : '') : false;
        if ($step === false) $secErr = '코드가 맞지 않습니다. 앱에 표시된 현재 6자리를 입력하세요. (NAS 와 휴대폰 시간이 맞는지도 확인)';
        elseif (!otp_save(['secret' => $pending, 'created' => now_str(), 'last_step' => $step])) $secErr = 'data 폴더에 저장하지 못했습니다. 쓰기 권한을 확인하세요.';
        else { unset($_SESSION['otp_pending']); $secMsg = 'OTP 가 등록되었습니다. 다음 로그인부터 OTP 코드가 필요합니다.'; }
    } elseif (isset($_POST['otp_disable'])) {
        if (!otp_check_login(isset($_POST['otp']) ? $_POST['otp'] : '')) $secErr = '현재 OTP 코드가 맞지 않아 해제하지 않았습니다.';
        else { @unlink(OTP_FILE); $secMsg = 'OTP 등록을 해제했습니다.' . (otp_mode() !== 'off' ? ' 다시 등록하기 전까지는 비밀번호만으로 로그인됩니다.' : ''); }
    }
}

// DB 연결 확인 (실패해도 배포 기능은 쓸 수 있게)
$dbError = '';
try { db(); } catch (Exception $e) { $dbError = $e->getMessage(); }

$page = isset($_GET['page']) ? $_GET['page'] : '';
$app = null;
if (isset($_GET['app'])) {
    $app = load_app($_GET['app']);
    if (!$app) { header('Location: ./'); exit; }
    if (isset($_GET['ini'])) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="updater.ini"');
        echo "\xEF\xBB\xBF" . updater_ini($app);
        exit;
    }
}

function db_warning($dbError) {
    if ($dbError) echo '<div class="card err">DB 연결 실패: ' . h($dbError) . '<br><span class="small-text">PC 현황/업데이트 이력 기능을 쓰려면 config.php 의 DB 설정과 data 폴더 쓰기 권한을 확인하세요. (배포 기능은 그대로 사용 가능)</span></div>';
    if (ADMIN_PASSWORD === 'change-me' && !(otp_mode() === 'otp' && otp_registered())) echo '<div class="card err">⚠ 기본 비밀번호를 사용 중입니다. config.php 의 ADMIN_PASSWORD 를 바꾸세요.</div>';
    if (otp_mode() !== 'off' && !otp_registered()) echo '<div class="card err">⚠ Google OTP 가 아직 등록되지 않았습니다. <a href="?page=security">보안</a> 메뉴에서 등록하세요.</div>';
}

if ($app) {
    $tab = isset($_GET['tab']) ? $_GET['tab'] : 'deploy';
    if (!in_array($tab, ['deploy', 'pcs', 'logs', 'settings'], true)) $tab = 'deploy';
    page_head($app['name'] . ' - ' . SITE_TITLE);
    page_header('', $app['name']);
    echo '<main>';
    db_warning($dbError);
    $q = '?app=' . urlencode($app['id']);
    echo '<div class="subnav">';
    foreach (['deploy' => '배포', 'pcs' => 'PC 현황', 'logs' => '업데이트 이력', 'settings' => '설정 / 설치'] as $k => $v)
        echo '<a href="' . $q . '&tab=' . $k . '" class="' . ($tab === $k ? 'on' : '') . '">' . $v . '</a>';
    echo '</div>';
    include __DIR__ . '/views/app_' . $tab . '.php';
    echo '</main>';
} elseif ($page === 'check') {
    page_head('설치 점검 - ' . SITE_TITLE);
    page_header('check');
    echo '<main>';
    include __DIR__ . '/views/check.php';
    echo '</main>';
} elseif ($page === 'security') {
    page_head('보안 - ' . SITE_TITLE);
    page_header('security');
    echo '<main>';
    include __DIR__ . '/views/security.php';
    echo '</main>';
} elseif ($page === 'logs') {
    page_head('업데이트 이력 - ' . SITE_TITLE);
    page_header('logs');
    echo '<main>';
    db_warning($dbError);
    include __DIR__ . '/views/app_logs.php';
    echo '</main>';
} else {
    page_head(SITE_TITLE);
    page_header('dash');
    echo '<main>';
    db_warning($dbError);
    include __DIR__ . '/views/dashboard.php';
    echo '</main>';
}
?>
</body>
</html>
