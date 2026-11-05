<?php if (!defined('APP_ROOT')) exit;
$s = $app;
$hasUpdaterExe = is_file(APP_ROOT . '/tools/Updater.exe');
?>
<form id="settingsForm">
<div class="card">
  <h2>솔루션 정보</h2>
  <div class="form-grid">
    <label>솔루션 ID</label><div class="mono"><?= h($s['id']) ?> <span class="muted small-text">(변경 불가)</span></div>
    <label>솔루션명</label><input type="text" id="setName" value="<?= h($s['name']) ?>">
    <label>솔루션 코드</label><input type="text" id="setCode" value="<?= h($s['code']) ?>" placeholder="업무상 구분 코드 (선택)">
    <label>설명</label><input type="text" id="setMemo" value="<?= h($s['memo']) ?>">
    <label>등록일</label><div class="muted"><?= h($s['created'] ?: '-') ?></div>
  </div>
</div>

<div class="card">
  <h2>런처 / 업데이트 정책</h2>
  <div class="form-grid">
    <label>사용 여부</label>
    <label style="color:var(--text)"><input type="checkbox" id="setActive" <?= $s['active'] ? 'checked' : '' ?>> 활성 <span class="muted small-text">(끄면 모든 PC가 업데이트 없이 현재 버전으로 실행)</span></label>
    <label>서버 연결 실패 시</label>
    <label style="color:var(--text)"><input type="checkbox" id="setOffline" <?= $s['offlineRun'] ? 'checked' : '' ?>> 기존 설치 버전으로 실행 허용 <span class="muted small-text">(끄면 서버 연결이 될 때까지 실행 차단)</span></label>
    <label>메인 실행 파일</label>
    <div class="row">
      <input type="text" id="setLaunch" value="<?= h($s['launch']) ?>" placeholder="예: MWAgent.exe" size="26" list="exeList">
      <datalist id="exeList"><?php foreach ($s['files'] as $path => $f) if (preg_match('/\.exe$/i', $path)) echo '<option value="' . h($path) . '">'; ?></datalist>
      <label class="muted">실행 인자 <input type="text" id="setArgs" value="<?= h($s['launchArgs']) ?>" size="20"></label>
    </div>
    <label>런처 공지사항</label>
    <textarea id="setNotice" placeholder="런처 화면 상단에 항상 표시됩니다. (예: 11/10 22시 서버 점검 예정)"><?= h($s['notice']) ?></textarea>
  </div>
  <div class="row" style="margin-top:12px;justify-content:flex-end"><button>저장</button></div>
  <p class="muted small-text">PC의 updater.ini 에 LaunchExe 를 적으면 그 값이 우선합니다. 실행 파일·인자를 바꾸면 새 빌드로 기록됩니다.</p>
</div>
</form>

<div class="card">
  <h2>PC 설치 방법</h2>
  <ol style="margin:0 0 10px;padding-left:20px;line-height:1.8">
    <li>아래 두 파일을 받아 각 PC의 bin 폴더(예: <span class="mono">C:\<?= h($s['id']) ?>\bin</span>)에 넣습니다.</li>
    <li>바탕화면 바로가기를 메인 프로그램 대신 <b>Updater.exe</b> 로 만듭니다.</li>
    <li>처음 실행하면 PC가 자동 등록되고 전체 파일을 받습니다. 이후에는 바뀐 파일만 받습니다.</li>
  </ol>
  <div class="row" style="margin-bottom:8px">
    <?php if ($hasUpdaterExe): ?><a class="btn secondary" href="tools/Updater.exe" download>Updater.exe 받기</a><?php else: ?><span class="muted">(server/tools/Updater.exe 를 올려두면 여기서 받을 수 있어요)</span><?php endif; ?>
    <a class="btn secondary" href="?app=<?= urlencode($s['id']) ?>&ini=1">이 솔루션용 updater.ini 받기</a>
  </div>
  <pre><?= h(trim(updater_ini($s))) ?></pre>
  <p class="muted small-text">Manifest: <a class="mono" href="manifests/<?= h(rawurlencode($s['id'])) ?>.json" target="_blank"><?= h(base_url()) ?>manifests/<?= h($s['id']) ?>.json</a><br>
  업데이트 확인 API: <span class="mono"><?= h(base_url()) ?>api/v1/launcher/check.php</span></p>
</div>

<div class="card">
  <h2>솔루션 삭제</h2>
  <p class="muted small-text">서버에서 이 솔루션과 배포 이력, PC 현황이 모두 삭제됩니다. PC에 설치된 파일은 지워지지 않지만 더 이상 업데이트되지 않습니다.</p>
  <button type="button" class="danger" onclick="deleteApp()">솔루션 삭제</button>
</div>

<?php script_common(); ?>
<script>
const APP = <?= json_encode($s['id']) ?>;
document.getElementById('settingsForm').onsubmit = async e => {
  e.preventDefault();
  try {
    await api({action:'update_app', app:APP, name:setName.value, code:setCode.value, memo:setMemo.value, notice:setNotice.value,
               active: setActive.checked ? '1' : '', offlineRun: setOffline.checked ? '1' : '', launch:setLaunch.value, launchArgs:setArgs.value});
    location.reload();
  } catch (err) { alert(err.message); }
};
async function deleteApp() {
  const v = prompt('정말 삭제하려면 솔루션 ID(' + APP + ')를 입력하세요.');
  if (v === null) return;
  try { await api({action:'delete_app', app:APP, confirm:v}); location.href = './'; }
  catch (e) { alert(e.message); }
}
</script>
