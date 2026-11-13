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

<?php
$sk = sign_key();
$unsigned = [];
if ($sk) foreach (list_apps() as $a) if ($a['files'] && !sign_state_ok($a)) $unsigned[] = $a;
?>
<div class="card" id="signing">
  <h2>배포 전자서명 <?php if ($sk): ?><span class="tag success">사용 중</span><?php else: ?><span class="tag gray">사용 안 함</span><?php endif; ?></h2>
  <p>서명 키를 등록하면 배포·롤백할 때 OTP 대신 <b>키 파일 + 키 암호</b>로 서명해야 반영됩니다. 각 PC 의 런처는 서명이 맞지 않는 업데이트를 설치하지 않으므로,
    <b>NAS 가 해킹되거나 비밀번호·OTP 가 유출돼도</b> 키 파일 없이는 악성 파일을 배포할 수 없습니다. 인증서 구매는 필요 없습니다.</p>
  <?php if (!sign_available()): ?><p class="err">이 서버의 PHP 에 openssl 확장이 꺼져 있어 쓸 수 없습니다. (Web Station › PHP 프로필 › 확장에서 openssl 체크)</p><?php endif; ?>
  <p class="err small-text hidden" id="httpsWarn">⚠ 지금 http 로 접속 중입니다. 브라우저 보안 정책상 전자서명(키 만들기/서명)은 <b>https</b> 접속에서만 됩니다. (시놀로지: 제어판 › 보안 › 인증서에서 Let's Encrypt 발급)</p>

  <?php if ($sk): ?>
    <p>등록된 키: <b class="mono" style="font-size:14px"><?= h($sk['fingerprint']) ?></b> <span class="muted small-text">RSA <?= intval($sk['bits']) ?> · <?= h($sk['created']) ?> 등록</span></p>
    <p class="small-text">PC 에 적용: 아래 <b>기존 PC 런처 교체</b>에서 [새 런처 배포]를 누르면 새 Updater.exe 와 공개 키 파일(updater.signkey)이 모든 솔루션에 배포됩니다.
      PC 가 한 번 업데이트를 받으면 그다음부터는 서명된 배포만 설치합니다. (새로 설치하는 PC 는 [설정 / 설치] 탭의 updater.ini 에 SignKey 가 들어 있습니다)</p>
    <pre style="white-space:pre-wrap;word-break:break-all">SignKey=<?= h(sign_ini_value()) ?></pre>
    <?php if ($unsigned): ?>
      <p class="warn">⚠ 현재 버전에 서명이 없는 솔루션: <?php foreach ($unsigned as $a): ?><a href="?app=<?= urlencode($a['id']) ?>"><?= h($a['name']) ?></a> <?php endforeach; ?> — 솔루션 배포 탭의 [지금 서명]을 누르세요.</p>
    <?php endif; ?>
    <div class="row">
      <button type="button" class="secondary" onclick="showCreate()">새 키로 교체</button>
      <button type="button" class="secondary" onclick="pickExisting.click()">다른 키 파일로 교체</button>
      <button type="button" class="danger" onclick="removeKey()">전자서명 해제</button>
    </div>
    <p class="muted small-text">키 교체 순서: ① [새 키로 교체]로 새 키 파일을 만들면 <code>SignKey=옛키,새키</code> 줄이 나옵니다 → ② 그 한 줄만 넣은 텍스트 파일 <code>updater.signkey</code> 를 각 솔루션 bin 바로 아래로 <b>지금 키로</b> 서명해 배포 → ③ PC 들이 받은 뒤 [다른 키 파일로 교체]로 새 키 파일을 등록.</p>
  <?php else: ?>
    <div class="row">
      <button type="button" onclick="showCreate()" <?= sign_available() ? '' : 'disabled' ?>>서명 키 만들기</button>
      <button type="button" class="secondary" onclick="pickExisting.click()" <?= sign_available() ? '' : 'disabled' ?>>가지고 있는 키 파일로 등록</button>
    </div>
  <?php endif; ?>
  <input type="file" id="pickExisting" accept=".json,application/json" hidden>

  <div id="createBox" class="hidden" style="margin-top:14px;border-top:1px solid var(--line);padding-top:14px">
    <p><b>키 암호</b>를 정하세요. 키 파일을 누가 가져가도 이 암호 없이는 서명할 수 없습니다. (8자 이상)</p>
    <div class="row">
      <input type="password" id="kp1" placeholder="키 암호" autocomplete="new-password">
      <input type="password" id="kp2" placeholder="키 암호 확인" autocomplete="new-password">
      <button type="button" onclick="createKey()">만들고 키 파일 받기</button>
    </div>
    <p class="muted small-text">키 파일(.json)이 내려받아집니다. <b>USB 와 다른 PC 등 두 곳 이상에 백업</b>하세요. 잃어버리면 각 PC 의 SignKey 를 직접 바꿔야 합니다. 키 파일은 NAS 에 두지 마세요.</p>
    <p id="createMsg" class="small-text"></p>
  </div>
</div>

<?php script_common(); ?>
<script>
if (location.protocol !== 'https:' && !/^(localhost|127\.)/.test(location.hostname)) document.getElementById('httpsWarn').classList.remove('hidden');
function showCreate() { document.getElementById('createBox').classList.remove('hidden'); kp1.focus(); }
async function registerKey(spki, fp, note) {
  const r = await secureApi({action:'sign_key_set', spki}, {title: '서명 키 등록', okText: '등록',
    html: '<p>이 공개 키를 서버에 등록합니다.</p><p class="mono" style="font-size:14px">' + esc(fp) + '</p>' + (note || '')});
  if (r !== null) location.reload();
  return r;
}
async function createKey() {
  const msg = document.getElementById('createMsg');
  if (kp1.value.length < 8) { msg.className = 'small-text err'; msg.textContent = '키 암호는 8자 이상으로 하세요.'; return; }
  if (kp1.value !== kp2.value) { msg.className = 'small-text err'; msg.textContent = '키 암호가 서로 다릅니다.'; return; }
  try {
    msg.className = 'small-text'; msg.textContent = '키 만드는 중...';
    const k = await signKeyCreate(kp1.value);
    downloadJson('sign-key-' + k.fingerprint.replace(/:/g, '').slice(0, 8) + '.json', k.file);
    kp1.value = kp2.value = '';
    if (SIGNING) {   // 교체: 바로 등록하지 않고, PC 에 옛 키 + 새 키를 먼저 배포하도록 안내
      msg.className = 'small-text ok';
      msg.innerHTML = '새 키 파일을 받았습니다 (' + esc(k.fingerprint) + '). 아직 서버에는 등록하지 않았습니다.<br>아래 한 줄만 넣은 updater.signkey 파일을 각 솔루션에 지금 키로 배포하고, PC 들이 받은 뒤 [다른 키 파일로 교체]로 새 키 파일을 등록하세요.' +
        '<pre style="white-space:pre-wrap;word-break:break-all">SignKey=' + esc(SIGN_INI + ',' + k.ini) + '</pre>';
      return;
    }
    msg.className = 'small-text ok'; msg.textContent = '키 파일을 받았습니다 (' + k.fingerprint + '). 서버에 등록하세요.';
    await registerKey(k.spki, k.fingerprint, '<p class="warn small-text">방금 받은 키 파일을 백업했는지 확인하세요.</p>');
  } catch (e) { msg.className = 'small-text err'; msg.textContent = e.message; }
}
pickExisting.onchange = async e => {
  const f = e.target.files[0]; e.target.value = '';
  if (!f) return;
  try { const k = await readKeyFile(f); await registerKey(k.spki, k.fingerprint); } catch (err) { alert(err.message); }
};
async function removeKey() {
  try {
    const r = await secureApi({action:'sign_key_remove'}, {title: '전자서명 해제', okText: '해제', danger: true,
      html: '<p>서버의 서명 키를 지웁니다. 이후 배포는 OTP 로 확인하며 서명 없이 나갑니다.<br><b>SignKey 가 들어간 PC 는 서명 없는 업데이트를 받지 않으니</b>, 해제 전에 SignKey 를 뺀 updater.ini 를 먼저 배포하세요.</p>'});
    if (r !== null) location.reload();
  } catch (e) { alert(e.message); }
}
</script>

<?php
$lfSha = is_file(LAUNCHER_SRC) ? hash_file('sha256', LAUNCHER_SRC) : '';
$keyLine = $sk ? "; 배포 전자서명 공개 키 (관리 사이트에서 자동 배포, 지우지 마세요)\r\nSignKey=" . sign_ini_value() . "\r\n" : '';
$keySha = $sk ? hash('sha256', $keyLine) : '';
$lrows = []; $lneed = 0;
foreach (list_apps() as $id => $a) {
    if (!$a['files']) continue;
    $exe = null; $kf = null;
    foreach ($a['files'] as $p => $f) { if (strcasecmp($p, 'Updater.exe') === 0) $exe = $f; if (strcasecmp($p, SIGNKEY_PC_FILE) === 0) $kf = $f; }
    $exeOk = $exe && $exe['sha256'] === $lfSha;
    $keyOk = !$sk || ($kf && $kf['sha256'] === $keySha);
    if (!$exeOk || !$keyOk) $lneed++;
    $vers = [];
    try { foreach (db_all('SELECT launcher_version v, COUNT(*) n FROM client_install WHERE app_id = ? GROUP BY launcher_version', [$id]) as $r) $vers[] = ($r['v'] ?: '?') . ' × ' . $r['n']; } catch (Exception $e) {}
    $lrows[] = [$a, $exe, $exeOk, $keyOk, $vers];
}
?>
<div class="card" id="launcher">
  <h2>기존 PC 런처 교체 <span class="muted small-text">서버 Updater.exe SHA-256 <?= h(substr($lfSha, 0, 12)) ?>…</span></h2>
  <p class="small-text">이미 설치된 PC 의 런처(Updater.exe)를 이 서버에 있는 새 버전으로 바꿉니다. 각 솔루션에 새 런처<?= $sk ? '와 공개 키 파일(updater.signkey)' : '' ?>를 넣어 배포하면,
    PC 가 다음에 실행될 때 기존 런처가 자기 자신을 새 런처로 교체합니다. 각 PC 의 updater.ini(서버 주소 등 설정)는 건드리지 않습니다.</p>
  <?php if (!$lrows): ?><p class="muted">배포 중인 솔루션이 없습니다.</p><?php else: ?>
  <div class="tablewrap"><table>
    <thead><tr><th>솔루션</th><th>배포 중인 런처</th><?php if ($sk): ?><th>공개 키 파일</th><?php endif; ?><th>PC 가 보고한 런처 버전</th></tr></thead>
    <tbody>
    <?php foreach ($lrows as $r): ?>
      <tr>
        <td><a href="?app=<?= urlencode($r[0]['id']) ?>"><?= h($r[0]['name']) ?></a></td>
        <td><?= $r[2] ? '<span class="tag success">최신</span>' : ($r[1] ? '<span class="tag upd">구버전</span>' : '<span class="tag gray">없음</span>') ?></td>
        <?php if ($sk): ?><td><?= $r[3] ? '<span class="tag success">배포됨</span>' : '<span class="tag upd">필요</span>' ?></td><?php endif; ?>
        <td class="small-text"><?= h(implode(', ', $r[4]) ?: '-') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="row" style="margin-top:10px">
    <button type="button" onclick="upgradeLauncher()" <?= $lneed ? '' : 'disabled' ?>><?= $lneed ? '새 런처 배포 (' . $lneed . '개 솔루션)' : '모두 최신입니다' ?></button>
    <span class="muted small-text">PC 가 실제로 바뀌었는지는 각 솔루션의 PC 현황에서 런처 버전(2.1.0)으로 확인하세요.</span>
  </div>
  <?php endif; ?>
</div>
<script>
async function upgradeLauncher() {
  try {
    const r = await secureApi({action:'upgrade_launcher'}, {title: '기존 PC 런처 교체', okText: '배포', danger: true, publish: true,
      html: '<p><b><?= $lneed ?>개 솔루션</b>에 새 런처(Updater.exe)<?= $sk ? '와 공개 키 파일' : '' ?>를 새 버전으로 배포합니다. 각 PC 는 다음 실행 때 런처를 교체합니다.</p>' +
        '<p class="mono small-text">Updater.exe SHA-256 <?= h($lfSha) ?></p>'});
    if (r === null) return;
    alert('배포했습니다:\n' + (r.done.join('\n') || '바뀐 것 없음'));
    location.reload();
  } catch (e) { alert(e.message); }
}
</script>

<?php
$auditName = ['login' => '로그인', 'login_fail' => '로그인 실패', 'stepup_fail' => '재인증 실패', 'stepup_logout' => '재인증 실패로 로그아웃',
              'deploy' => '배포', 'rollback' => '롤백', 'delete_app' => '솔루션 삭제', 'create_app' => '솔루션 등록',
              'launch_change' => '실행 설정 변경', 'otp_enable' => 'OTP 등록', 'otp_disable' => 'OTP 해제',
              'sign_key_set' => '서명 키 등록', 'sign_key_remove' => '서명 해제', 'sign_fail' => '서명 불일치', 'sign_current' => '현재 버전 서명'];
$auditBad = ['login_fail', 'stepup_fail', 'stepup_logout', 'otp_disable', 'delete_app', 'sign_fail', 'sign_key_remove', 'sign_key_set'];
$audit = audit_recent(100);
?>
<div class="card">
  <h2>보안 감사 로그 <span class="muted small-text">최근 100건 · data/audit.log</span></h2>
  <p class="muted small-text">배포·롤백은 <?= signing_enabled() ? '전자서명' : (stepup_kind() === 'otp' ? 'OTP 재확인' : '비밀번호 재확인') ?>, 솔루션 삭제·키 변경은 OTP 재확인을 거치며 여기에 기록합니다. 모르는 IP 나 시각이 보이면 즉시 비밀번호를 바꾸고 OTP 를 다시 등록하세요.</p>
  <?php if (!$audit): ?><p class="muted">기록이 없습니다.</p><?php else: ?>
  <div class="tablewrap"><table>
    <thead><tr><th>시각</th><th>구분</th><th>IP</th><th>내용</th></tr></thead>
    <tbody>
    <?php foreach ($audit as $a):
        $ev = isset($a['event']) ? $a['event'] : '';
        $detail = [];
        if (!empty($a['app'])) $detail[] = $a['app'] . (!empty($a['label']) ? ' v' . $a['label'] : '');
        if (!empty($a['toLabel'])) $detail[] = '→ v' . $a['toLabel'];
        if (!empty($a['what'])) $detail[] = $a['what'];
        if (!empty($a['to'])) $detail[] = '→ ' . $a['to'];
        if (!empty($a['memo'])) $detail[] = cut($a['memo'], 60);
        if (!empty($a['signed'])) $detail[] = '서명됨';
    ?>
      <tr>
        <td class="nowrap small-text"><?= h(isset($a['time']) ? $a['time'] : '') ?></td>
        <td class="nowrap"><span class="tag <?= in_array($ev, $auditBad, true) ? 'fail' : ($ev === 'deploy' || $ev === 'rollback' ? 'upd' : 'gray') ?>"><?= h(isset($auditName[$ev]) ? $auditName[$ev] : $ev) ?></span></td>
        <td class="mono nowrap" title="<?= h(isset($a['ua']) ? $a['ua'] : '') ?>"><?= h(isset($a['ip']) ? $a['ip'] : '') ?></td>
        <td class="small-text"><?= h(implode(' · ', $detail)) ?>
          <?php if (!empty($a['files'])): ?>
          <details><summary class="muted"><?= count($a['files']) ?>개 파일 해시</summary>
            <ul class="hist-files mono"><?php foreach ($a['files'] as $p => $sha): ?><li><?= h($p) ?> <span class="muted"><?= h($sha) ?></span></li><?php endforeach; ?></ul>
          </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
