<?php if (!defined('ADMIN_PASSWORD')) exit;
$otp = otp_load();
$modeName = ['password+otp' => '비밀번호 + OTP', 'otp' => 'OTP 만', 'off' => '비밀번호만 (OTP 사용 안 함)'][otp_mode()];
if (!$otp && empty($_SESSION['otp_pending'])) $_SESSION['otp_pending'] = otp_new_secret();
?>
<?php if ($secMsg): ?><div class="card ok"><?= h($secMsg) ?></div><?php endif; ?>
<?php if ($secErr): ?><div class="card err"><?= h($secErr) ?></div><?php endif; ?>

<div class="card">
  <h2>Google OTP 로그인</h2>
  <p>로그인 방식: <b><?= h($modeName) ?></b> <span class="muted">(config.php 의 OTP_MODE 에서 변경)</span></p>
  <p>NAS 현재 시각: <b><?= h(now_str()) ?></b> <span class="muted">OTP 는 시간 기준이라 NAS 시각이 1분 이상 틀리면 코드가 맞지 않습니다. (제어판 › 지역 옵션 › NTP 서버와 동기화)</span></p>
</div>

<?php if ($otp): ?>
<div class="card">
  <h3><span class="tag success">등록됨</span> <?= h($otp['created']) ?> 등록<?php if (!empty($otp['last_login'])): ?> · 마지막 OTP 로그인 <?= h($otp['last_login']) ?><?php endif; ?></h3>
  <p class="muted">휴대폰을 바꾸거나 다시 등록하려면 아래에서 해제한 뒤 새로 등록하세요.<br>
  휴대폰을 잃어버려 코드를 볼 수 없으면 File Station 에서 <code>data/otp.json</code> 을 삭제하세요. 그러면 비밀번호로 로그인해 다시 등록할 수 있습니다.</p>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="text" name="otp" placeholder="현재 OTP 6자리" inputmode="numeric" autocomplete="one-time-code" maxlength="7">
    <button name="otp_disable" value="1" class="danger">OTP 해제</button>
  </form>
</div>
<?php else:
  $secret = $_SESSION['otp_pending'];
  $uri = otp_uri($secret, 'admin');
?>
<div class="card">
  <h3>OTP 등록</h3>
  <ol>
    <li>휴대폰에 <b>Google Authenticator</b> 앱을 설치합니다. (Microsoft Authenticator 등 다른 OTP 앱도 됩니다)</li>
    <li>앱에서 <b>+</b> › <b>QR 코드 스캔</b>으로 아래 QR 을 찍습니다.</li>
    <li>앱에 표시된 6자리를 입력하고 [등록]을 누릅니다.</li>
  </ol>
  <div id="qr" style="background:#fff;padding:12px;display:inline-block;border:1px solid var(--line);border-radius:8px;min-width:224px;min-height:224px"></div>
  <p>QR 이 보이지 않거나 스캔이 안 되면 앱에서 <b>설정 키 입력</b>을 고르고 아래 키를 입력하세요. (계정 이름은 아무거나, 키 유형은 <b>시간 기준</b>)</p>
  <p><code style="font-size:16px;letter-spacing:2px"><?= h(trim(chunk_split($secret, 4, ' '))) ?></code></p>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="text" name="otp" placeholder="앱의 6자리 코드" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus>
    <button name="otp_enable" value="1">등록</button>
  </form>
  <p class="muted">이 키는 이 화면에서만 보입니다. 다른 사람에게 보여주지 마세요.</p>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
  (function () {
    var el = document.getElementById('qr');
    if (window.QRCode) new QRCode(el, { text: <?= json_encode($uri) ?>, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.M });
    else el.innerHTML = '<p class="muted" style="width:200px">QR 을 불러오지 못했습니다. 아래 설정 키를 직접 입력하세요.</p>';
  })();
</script>
<?php endif; ?>
