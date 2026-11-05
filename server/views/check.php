<?php if (!defined('APP_ROOT')) exit;
// 설치 환경 점검: 시놀로지 Web Station 등 새 서버에 올린 뒤 확인용
function ini_bytes($v) {
    $v = trim((string)$v); if ($v === '') return 0;
    $n = (float)$v; $u = strtolower(substr($v, -1));
    if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
    return (int)$n;
}
$checks = [];
$add = function ($name, $ok, $value, $fix = '') use (&$checks) { $checks[] = [$name, $ok, $value, $fix]; };

$add('PHP 버전', version_compare(PHP_VERSION, '7.0.0', '>='), PHP_VERSION, 'PHP 7.0 이상 필요 (시놀로지: PHP 8.2 패키지 권장)');
$add('PDO 확장', extension_loaded('pdo'), extension_loaded('pdo') ? '사용 가능' : '없음', 'PHP 프로필에서 pdo 확장 활성화');
$isSqlite = stripos(DB_DSN, 'sqlite:') === 0;
if ($isSqlite) $add('pdo_sqlite 확장', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? '사용 가능' : '없음', 'Web Station › 스크립트 언어 설정 › PHP 프로필 › 확장에서 pdo_sqlite, sqlite3 체크');
else $add('pdo_mysql 확장', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? '사용 가능' : '없음', 'PHP 프로필에서 pdo_mysql 체크');
try { db(); $add('DB 연결', true, $isSqlite ? 'SQLite (data/updater.db)' : 'MySQL'); }
catch (Exception $e) { $add('DB 연결', false, $e->getMessage(), $isSqlite ? 'data 폴더 쓰기 권한과 pdo_sqlite 확장을 확인' : 'config.php 의 DB_DSN/계정 확인'); }
$add('mbstring 확장 (선택)', extension_loaded('mbstring'), extension_loaded('mbstring') ? '사용 가능' : '없음 (없어도 동작)', '');
foreach (['files', 'manifests', 'data'] as $d) {
    $path = APP_ROOT . '/' . $d;
    $ok = is_dir($path) && is_writable($path);
    $add($d . '/ 쓰기 권한', $ok, $ok ? '쓰기 가능' : '쓰기 불가', 'File Station › ' . $d . ' 폴더 › 속성 › 권한 › http 그룹에 읽기/쓰기 부여');
}
$up = ini_get('upload_max_filesize'); $post = ini_get('post_max_size');
$add('업로드 한도 (upload_max_filesize)', ini_bytes($up) >= 100 * 1048576, $up, 'PHP 프로필 › 코어 설정에서 upload_max_filesize 를 배포 파일보다 크게 (예: 512M)');
$add('요청 한도 (post_max_size)', ini_bytes($post) >= ini_bytes($up), $post, 'post_max_size 는 upload_max_filesize 이상으로 (예: 520M)');
$add('실행 시간 (max_execution_time)', intval(ini_get('max_execution_time')) === 0 || intval(ini_get('max_execution_time')) >= 120, ini_get('max_execution_time') . '초', '큰 파일 업로드를 위해 300 권장');
$add('시간대', true, date_default_timezone_get() . ' (현재 ' . now_str() . ')', '');
$add('웹서버', true, isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '-', '');
$add('HTTPS', !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'), base_url(), '운영 시 HTTPS 권장 (Let\'s Encrypt 인증서)');
?>
<div class="card">
  <h2>설치 점검</h2>
  <table>
    <thead><tr><th>항목</th><th>상태</th><th>현재 값</th><th>해결 방법</th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): ?>
      <tr>
        <td class="nowrap"><?= h($c[0]) ?></td>
        <td><?= $c[1] ? '<span class="tag success">정상</span>' : '<span class="tag fail">확인 필요</span>' ?></td>
        <td class="small-text mono"><?= h($c[2]) ?></td>
        <td class="small-text muted"><?= $c[1] ? '' : h($c[3]) ?></td>
      </tr>
    <?php endforeach; ?>
      <tr>
        <td class="nowrap">data/ 외부 접근 차단</td>
        <td id="protState"><span class="tag gray">확인 중</span></td>
        <td class="small-text mono" id="protValue"></td>
        <td class="small-text muted" id="protFix"></td>
      </tr>
      <tr>
        <td class="nowrap">런처 API</td>
        <td id="apiState"><span class="tag gray">확인 중</span></td>
        <td class="small-text mono" id="apiValue"></td>
        <td class="small-text muted" id="apiFix"></td>
      </tr>
    </tbody>
  </table>
  <p class="muted small-text">"data/ 외부 접근 차단"이 확인 필요로 나오면 PC 현황 DB(updater.db)가 인터넷에 노출될 수 있습니다. 시놀로지에서는 웹 서비스의 HTTP 백엔드를 <b>Apache HTTP Server 2.4</b> 로 설정하세요.</p>
</div>
<script>
(async () => {
  const set = (id, ok, val, fix) => {
    document.getElementById(id + 'State').innerHTML = ok ? '<span class="tag success">정상</span>' : '<span class="tag fail">확인 필요</span>';
    document.getElementById(id + 'Value').textContent = val;
    document.getElementById(id + 'Fix').textContent = ok ? '' : fix;
  };
  try {
    const r = await fetch('data/index.html', {cache:'no-store'});
    set('prot', r.status === 403 || r.status === 404, 'data/index.html → HTTP ' + r.status, '.htaccess 가 적용되지 않습니다. HTTP 백엔드를 Apache 로 바꾸거나 nginx 에서 /data, /views 경로를 차단하세요.');
  } catch (e) { set('prot', false, e.message, ''); }
  try {
    const r = await fetch('api/v1/launcher/check.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:'{"solutionId":"__check__","pcId":"PC-CHECK"}'});
    const j = await r.json().catch(() => null);
    // 404(테스트용 솔루션 없음) 또는 401(인증키 사용 중)이면 API 가 정상 동작하는 것
    const ok = j && j.success === false && (r.status === 404 || r.status === 401);
    set('api', ok, ok ? (r.status === 401 ? '정상 응답 (런처 인증키 사용 중)' : '정상 응답') : 'HTTP ' + r.status + (j ? ' ' + (j.error || '') : ''), 'api/v1/launcher 폴더가 올라갔는지, PHP 가 실행되는지 확인하세요.');
  } catch (e) { set('api', false, e.message, ''); }
})();
</script>
