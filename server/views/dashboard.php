<?php if (!defined('APP_ROOT')) exit;

$apps = list_apps();
$stats = []; $totPc = 0; $totLatest = 0; $totInst = 0; $ok7 = 0; $fail7 = 0; $inactive = 0; $recentFails = [];
if (!$dbError) {
    foreach ($apps as $a) { $stats[$a['id']] = install_stats($a); $totInst += $stats[$a['id']]['total']; $totLatest += $stats[$a['id']]['latest']; $inactive += $stats[$a['id']]['inactive']; }
    $r = db_one('SELECT COUNT(DISTINCT pc_id) AS n FROM client_install'); $totPc = $r ? intval($r['n']) : 0;
    $since = date('Y-m-d H:i:s', time() - 7 * 86400);
    foreach (db_all('SELECT result, COUNT(*) AS n FROM update_history WHERE time >= ? GROUP BY result', [$since]) as $row) {
        if ($row['result'] === 'success') $ok7 = intval($row['n']);
        if ($row['result'] === 'fail') $fail7 = intval($row['n']);
    }
    $recentFails = db_all("SELECT * FROM update_history WHERE result = 'fail' ORDER BY id DESC LIMIT 8");
}
$recentDeploys = [];
foreach ($apps as $a) foreach (array_slice(load_history($a['id']), 0, 10) as $hst) if ($hst['type'] !== 'settings') $recentDeploys[] = $hst + ['app' => $a];
usort($recentDeploys, function ($x, $y) { return strcmp($y['time'], $x['time']); });
$recentDeploys = array_slice($recentDeploys, 0, 8);
$pct = $totInst ? round($totLatest * 100 / $totInst) : 0;
?>
<div class="tiles">
  <div class="tile"><span>등록 솔루션</span><b><?= count($apps) ?></b></div>
  <div class="tile"><span>설치 PC</span><b><?= $totPc ?></b></div>
  <div class="tile"><span>최신 버전 설치율</span><b><?= $totInst ? $pct . '%' : '-' ?></b><div class="meter"><i style="width:<?= $pct ?>%"></i></div></div>
  <a class="tile" href="?page=logs&result=success"><span>업데이트 성공 (7일)</span><b class="ok"><?= $ok7 ?></b></a>
  <a class="tile" href="?page=logs&result=fail"><span>업데이트 실패 (7일)</span><b class="<?= $fail7 ? 'err' : '' ?>"><?= $fail7 ?></b></a>
  <div class="tile"><span>장기 미접속 (<?= INACTIVE_DAYS ?>일+)</span><b><?= $inactive ?></b></div>
</div>

<div class="card">
  <h2>솔루션 <span class="muted small-text"><?= count($apps) ?>개</span></h2>
  <?php if ($apps): ?>
  <div class="apps">
    <?php foreach ($apps as $a): $st = isset($stats[$a['id']]) ? $stats[$a['id']] : null; ?>
      <a class="app" href="?app=<?= urlencode($a['id']) ?>">
        <h3><?= h($a['name']) ?><?php if (!$a['active']): ?><span class="tag gray">비활성</span><?php endif; ?></h3>
        <div class="muted mono"><?= h($a['id']) ?><?= $a['code'] ? ' · ' . h($a['code']) : '' ?></div>
        <?php if ($a['memo']): ?><div class="muted small-text" style="margin-top:6px"><?= h($a['memo']) ?></div><?php endif; ?>
        <div class="meta">
          <div>버전<b><?= h($a['versionLabel'] ?: '-') ?></b></div>
          <div>파일<b><?= count($a['files']) ?></b></div>
          <div>설치 PC<b><?= $st ? $st['total'] : '-' ?></b></div>
          <div>최신<b><?= $st && $st['total'] ? round($st['latest'] * 100 / $st['total']) . '%' : '-' ?></b></div>
          <?php if ($st && $st['failed']): ?><div>실패<b class="err"><?= $st['failed'] ?></b></div><?php endif; ?>
        </div>
        <div class="muted small-text" style="margin-top:8px">마지막 배포 <?= h($a['updated'] ?: '-') ?></div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
    <p class="muted">아직 등록된 솔루션이 없습니다. 아래에서 추가하세요. (예: MWAGENT)</p>
  <?php endif; ?>
</div>

<div class="row" style="align-items:stretch">
  <div class="card grow" style="min-width:320px">
    <h2>최근 배포</h2>
    <?php if (!$recentDeploys): ?><p class="muted">배포 내역이 없습니다.</p><?php else: ?>
    <table><tbody>
      <?php foreach ($recentDeploys as $d): ?>
        <tr>
          <td class="nowrap"><a href="?app=<?= urlencode($d['app']['id']) ?>"><?= h($d['app']['name']) ?></a></td>
          <td class="nowrap"><b><?= h(!empty($d['label']) ? 'v' . $d['label'] : '#' . $d['version']) ?></b><?= $d['type'] === 'rollback' ? '<span class="tag upd">롤백</span>' : '' ?></td>
          <td class="small-text"><?= h($d['memo']) ?></td>
          <td class="muted small-text nowrap"><?= h(substr($d['time'], 5, 11)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
  <div class="card grow" style="min-width:320px">
    <h2>최근 업데이트 오류 <a class="small-text" href="?page=logs&result=fail">전체 보기</a></h2>
    <?php if (!$recentFails): ?><p class="muted">오류가 없습니다.</p><?php else: ?>
    <table><tbody>
      <?php foreach ($recentFails as $f): ?>
        <tr>
          <td class="nowrap"><a href="?app=<?= urlencode($f['app_id']) ?>&tab=logs"><?= h($f['app_id']) ?></a></td>
          <td class="nowrap"><?= h($f['pc_name']) ?></td>
          <td class="small-text err"><?= h(cut($f['message'], 80)) ?></td>
          <td class="muted small-text nowrap"><?= h(substr($f['time'], 5, 11)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>새 솔루션 등록</h2>
  <form class="row" id="createForm">
    <input type="text" id="newId" placeholder="솔루션 ID (예: MWAGENT)" required pattern="[A-Za-z0-9][A-Za-z0-9_\-]{0,39}" title="영문/숫자/-/_ 40자 이내">
    <input type="text" id="newName" placeholder="솔루션명 (예: MW 에이전트)" class="grow">
    <input type="text" id="newCode" placeholder="솔루션 코드 (선택)" size="14">
    <select id="copyFrom">
      <option value="">빈 상태로 시작</option>
      <?php foreach ($apps as $a): ?><option value="<?= h($a['id']) ?>"><?= h($a['name']) ?> 의 파일 복사</option><?php endforeach; ?>
    </select>
    <button>등록</button>
  </form>
  <p class="muted small-text">ID는 PC의 updater.ini 에 들어가는 값이라 나중에 바꿀 수 없습니다. 솔루션명과 코드는 언제든 바꿀 수 있어요.</p>
</div>
<?php script_common(); ?>
<script>
document.getElementById('createForm').onsubmit = async e => {
  e.preventDefault();
  const f = {action:'create_app', id:newId.value.trim(), name:newName.value.trim(), code:newCode.value.trim(), copyFrom:copyFrom.value};
  try {
    const r = f.copyFrom
      ? await secureApi(f, {title: '파일 복사하여 솔루션 등록', okText: '등록', publish: true,
          html: '<p><b>' + esc(f.copyFrom) + '</b> 의 파일을 복사해 <b>' + esc(f.id) + '</b> 를 만들고 v1.0.0 으로 배포합니다.</p>'})
      : await api(f);
    if (r) location.href = '?app=' + encodeURIComponent(r.id);
  } catch (err) { alert(err.message); }
};
</script>
