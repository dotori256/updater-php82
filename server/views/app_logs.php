<?php if (!defined('APP_ROOT')) exit;
if ($dbError) return;

// $app 이 있으면 해당 솔루션, 없으면 전체 솔루션
$result = isset($_GET['result']) ? $_GET['result'] : '';
$pcFilter = isset($_GET['pc']) ? $_GET['pc'] : '';
$pageNo = max(1, intval(isset($_GET['p']) ? $_GET['p'] : 1));
$per = 100;
$where = '1=1'; $args = [];
if ($app) { $where .= ' AND app_id = ?'; $args[] = $app['id']; }
if (in_array($result, ['success', 'fail', 'recovered', 'offline', 'blocked'], true)) { $where .= ' AND result = ?'; $args[] = $result; } else $result = '';
if ($pcFilter !== '') { $where .= ' AND pc_id = ?'; $args[] = $pcFilter; }
$total = intval(db_one("SELECT COUNT(*) AS n FROM update_history WHERE $where", $args)['n']);
$rows = db_all("SELECT * FROM update_history WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($pageNo - 1) * $per), $args);
$base = $app ? '?app=' . urlencode($app['id']) . '&tab=logs' : '?page=logs';
$link = function ($r, $p = 1) use ($base, $pcFilter) { return $base . ($r ? '&result=' . $r : '') . ($pcFilter !== '' ? '&pc=' . urlencode($pcFilter) : '') . ($p > 1 ? '&p=' . $p : ''); };
?>
<div class="card">
  <h2>업데이트 이력<?= $app ? '' : ' (전체 솔루션)' ?> <span class="muted small-text"><?= $total ?>건</span></h2>
  <div class="filters small-text" style="margin-bottom:10px">
    <?php foreach (['' => '전체', 'success' => '성공', 'fail' => '실패', 'recovered' => '복구', 'offline' => '오프라인 실행', 'blocked' => '차단'] as $k => $v): ?>
      <a href="<?= h($link($k)) ?>" class="<?= $result === $k ? 'on' : '' ?>"><?= $v ?></a>
    <?php endforeach; ?>
    <?php if ($pcFilter !== ''): ?><span class="tag">PC: <?= h($pcFilter) ?> <a href="<?= h($base . ($result ? '&result=' . $result : '')) ?>">×</a></span><?php endif; ?>
  </div>
  <?php if (!$rows): ?><p class="muted">이력이 없습니다. (최신 상태 확인은 기록하지 않고, 실제 업데이트·실패·복구만 기록합니다)</p><?php else: ?>
  <div class="tablewrap">
  <table>
    <thead><tr><th>시각</th><?php if (!$app): ?><th>솔루션</th><?php endif; ?><th>PC</th><th>결과</th><th>버전</th><th>파일</th><th>소요</th><th>내용</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="small-text muted nowrap"><?= h($r['time']) ?></td>
        <?php if (!$app): ?><td class="nowrap"><a href="?app=<?= urlencode($r['app_id']) ?>&tab=logs"><?= h($r['app_id']) ?></a></td><?php endif; ?>
        <td class="nowrap"><a href="<?= h($base . '&pc=' . urlencode($r['pc_id'])) ?>" style="text-decoration:none"><?= h($r['pc_name'] ?: $r['pc_id']) ?></a></td>
        <td><?= result_tag($r['result']) ?></td>
        <td class="nowrap small-text"><?= h($r['from_label'] ?: '-') ?> → <b><?= h($r['to_label'] ?: '-') ?></b></td>
        <td class="nowrap small-text"><?= intval($r['files']) ?>개 · <?= fmt_size(intval($r['bytes'])) ?></td>
        <td class="nowrap small-text muted"><?= $r['duration_ms'] ? round($r['duration_ms'] / 1000, 1) . '초' : '-' ?></td>
        <td class="small-text <?= $r['result'] === 'fail' ? 'err' : '' ?>"><?= nl2br(h($r['message'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($total > $per): ?>
    <div class="row" style="margin-top:10px">
      <?php if ($pageNo > 1): ?><a class="btn secondary small" href="<?= h($link($result, $pageNo - 1)) ?>">이전</a><?php endif; ?>
      <span class="muted small-text"><?= $pageNo ?> / <?= ceil($total / $per) ?></span>
      <?php if ($pageNo * $per < $total): ?><a class="btn secondary small" href="<?= h($link($result, $pageNo + 1)) ?>">다음</a><?php endif; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
