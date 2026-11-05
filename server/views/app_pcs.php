<?php if (!defined('APP_ROOT')) exit;
if ($dbError) return;

$s = $app;
$st = install_stats($s);
$filter = isset($_GET['f']) ? $_GET['f'] : 'all';
$cut = date('Y-m-d H:i:s', time() - INACTIVE_DAYS * 86400);
$where = 'i.app_id = ?'; $args = [$s['id']];
switch ($filter) {
    case 'latest':   $where .= ' AND i.build >= ?'; $args[] = intval($s['version']); break;
    case 'old':      $where .= ' AND i.build < ?'; $args[] = intval($s['version']); break;
    case 'failed':   $where .= " AND i.last_result = 'fail'"; break;
    case 'inactive': $where .= ' AND i.last_check < ?'; $args[] = $cut; break;
    case 'blocked':  $where .= ' AND i.blocked = 1'; break;
    default: $filter = 'all';
}
$rows = db_all("SELECT i.*, p.pc_name, p.last_ip, p.os_user FROM client_install i LEFT JOIN client_pc p ON p.pc_id = i.pc_id WHERE $where ORDER BY p.pc_name, i.pc_id", $args);
$q = '?app=' . urlencode($s['id']) . '&tab=pcs&f=';
$tiles = ['all' => ['전체 설치 PC', $st['total'], ''], 'latest' => ['최신 버전', $st['latest'], 'ok'], 'old' => ['구버전 (업데이트 대기)', $st['old'], 'warn'],
          'failed' => ['업데이트 실패', $st['failed'], 'err'], 'inactive' => ['장기 미접속 (' . INACTIVE_DAYS . '일+)', $st['inactive'], ''], 'blocked' => ['업데이트 차단', $st['blocked'], '']];
?>
<div class="tiles">
  <?php foreach ($tiles as $k => $t): ?>
    <a class="tile" href="<?= $q . $k ?>" style="<?= $filter === $k ? 'border-color:var(--accent)' : '' ?>"><span><?= h($t[0]) ?></span><b class="<?= $t[1] ? $t[2] : '' ?>"><?= $t[1] ?></b></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>PC 목록 <span class="muted small-text">운영 버전 v<?= h($s['versionLabel'] ?: '-') ?> (빌드 <?= h($s['version']) ?>)</span></h2>
  <?php if (!$rows): ?>
    <p class="muted">해당하는 PC가 없습니다. PC에서 런처를 한 번 실행하면 자동으로 등록됩니다.</p>
  <?php else: ?>
  <div class="tablewrap">
  <table>
    <thead><tr><th>PC명</th><th>설치 버전</th><th>마지막 결과</th><th>마지막 확인</th><th>마지막 업데이트</th><th class="hide-sm">런처</th><th class="hide-sm">PC ID / IP / 경로</th><th>메모</th><th>업데이트</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $isLatest = intval($r['build']) >= intval($s['version']); $stale = $r['last_check'] < $cut; ?>
      <tr data-pc="<?= h($r['pc_id']) ?>">
        <td><b><?= h($r['pc_name'] ?: '-') ?></b><?php if ($r['os_user']): ?><div class="muted small-text"><?= h($r['os_user']) ?></div><?php endif; ?></td>
        <td class="nowrap"><?= h($r['version_label'] ? 'v' . $r['version_label'] : '-') ?> <span class="tag <?= $isLatest ? 'success' : 'old' ?>"><?= $isLatest ? '최신' : '구버전' ?></span></td>
        <td><?= result_tag($r['last_result']) ?><?php if ($r['last_result'] === 'fail' && $r['last_message']): ?><div class="err small-text" style="max-width:260px"><?= h(cut($r['last_message'], 120)) ?></div><?php endif; ?></td>
        <td class="small-text nowrap <?= $stale ? 'warn' : 'muted' ?>"><?= h($r['last_check']) ?></td>
        <td class="small-text nowrap muted"><?= h($r['last_update_at'] ?: '-') ?></td>
        <td class="small-text muted hide-sm"><?= h($r['launcher_version']) ?></td>
        <td class="small-text muted hide-sm"><span class="mono"><?= h($r['pc_id']) ?></span><br><?= h($r['last_ip']) ?><br><span class="mono"><?= h($r['install_path']) ?></span></td>
        <td><input type="text" class="pcmemo" value="<?= h($r['memo']) ?>" size="10" onchange="savePc(this)"></td>
        <td><label class="small-text"><input type="checkbox" class="pcallow" <?= $r['blocked'] ? '' : 'checked' ?> onchange="savePc(this)"> 허용</label></td>
        <td><button type="button" class="secondary small" onclick="removePc(this)" title="목록에서 제거 (다시 실행하면 다시 등록됨)">제거</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted small-text">업데이트 '허용'을 끄면 그 PC는 업데이트 없이 현재 설치 버전으로 실행됩니다.</p>
  <?php endif; ?>
</div>
<?php script_common(); ?>
<script>
const APP = <?= json_encode($s['id']) ?>;
async function savePc(el) {
  const tr = el.closest('tr');
  try {
    await api({action:'pc_update', app:APP, pc:tr.dataset.pc, blocked: tr.querySelector('.pcallow').checked ? '' : '1', memo: tr.querySelector('.pcmemo').value});
    el.style.outline = '2px solid var(--ok)'; setTimeout(() => el.style.outline = '', 800);
  } catch (e) { alert(e.message); }
}
async function removePc(el) {
  const tr = el.closest('tr');
  if (!confirm('이 PC를 목록에서 제거할까요? (런처를 다시 실행하면 다시 등록됩니다)')) return;
  try { await api({action:'pc_update', app:APP, pc:tr.dataset.pc, remove:'1'}); tr.remove(); } catch (e) { alert(e.message); }
}
</script>
