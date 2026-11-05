<?php if (!defined('APP_ROOT')) exit;

$s = $app;
$history = load_history($s['id']);
$totalSize = 0; foreach ($s['files'] as $f) $totalSize += $f['size'];
$folders = [];
foreach (array_keys($s['files']) as $p) { $d = dirname($p); while ($d !== '.' && $d !== '') { $folders[$d] = true; $d = dirname($d); } }
ksort($folders, SORT_NATURAL | SORT_FLAG_CASE);
$typeLabel = ['deploy' => '배포', 'rollback' => '롤백', 'settings' => '설정', 'create' => '등록'];
$st = $dbError ? null : install_stats($s);
$suggest = suggest_label($s);
$maxLabel = max_label($s);
?>
<div class="card stats">
  <div><span class="muted">솔루션 ID</span><b class="mono" style="font-size:18px"><?= h($s['id']) ?></b></div>
  <div><span class="muted">운영 버전</span><b><?= h($s['versionLabel'] ? 'v' . $s['versionLabel'] : '-') ?></b><span class="muted small-text">빌드 <?= h($s['version']) ?></span></div>
  <div><span class="muted">파일 수</span><b><?= count($s['files']) ?></b></div>
  <div><span class="muted">전체 크기</span><b><?= fmt_size($totalSize) ?></b></div>
  <div><span class="muted">실행 파일</span><b style="font-size:15px;padding-top:4px"><?= h($s['launch'] ?: '미지정') ?></b></div>
  <?php if ($st): ?><div><span class="muted">설치 PC (최신/전체)</span><b><a href="?app=<?= urlencode($s['id']) ?>&tab=pcs" style="text-decoration:none"><?= $st['latest'] ?> / <?= $st['total'] ?></a></b></div><?php endif; ?>
  <?php if (!$s['active']): ?><div><span class="muted">상태</span><b class="err" style="font-size:15px;padding-top:4px">비활성 (업데이트 중지)</b></div><?php endif; ?>
</div>

<!-- 업데이트 올리기 -->
<div class="card">
  <h2>새 버전 배포</h2>
  <div class="drop" id="drop">바꿀 파일만 골라 올리면 그 파일만 업데이트됩니다. 나머지 파일은 그대로 유지돼요.<br>파일이나 폴더를 여기에 끌어다 놓거나 아래 버튼으로 선택하세요.</div>
  <div class="row">
    <label>bin 안의 위치 <input type="text" id="prefix" placeholder="비우면 bin 바로 아래" list="folderList" size="22"></label>
    <datalist id="folderList"><?php foreach ($folders as $d => $_) echo '<option value="' . h($d) . '">'; ?></datalist>
    <button type="button" class="secondary" onclick="pickFiles.click()">파일 선택</button>
    <button type="button" class="secondary" onclick="pickFolder.click()">폴더 선택</button>
    <label class="small-text"><input type="checkbox" id="stripTop" checked> 폴더를 올릴 때 최상위 폴더 이름 제외</label>
  </div>
  <input type="file" id="pickFiles" multiple hidden>
  <input type="file" id="pickFolder" webkitdirectory multiple hidden>
  <input type="file" id="pickReplace" hidden>

  <div id="pendingBox" class="hidden" style="margin-top:12px">
    <div class="tablewrap" style="max-height:320px;overflow:auto;margin-bottom:12px">
      <table>
        <thead><tr><th>배포할 경로</th><th>크기</th><th>구분</th><th></th></tr></thead>
        <tbody id="pendingRows"></tbody>
      </table>
    </div>
    <div class="form-grid">
      <label>새 버전</label>
      <div class="row">
        <input type="text" id="deployLabel" value="<?= h($suggest) ?>" size="10" placeholder="1.0.0">
        <span class="muted small-text">운영 <?= h($s['versionLabel'] ?: '없음') ?><?= $maxLabel && $maxLabel !== $s['versionLabel'] ? ' (최고 ' . h($maxLabel) . ')' : '' ?> ·
          <a href="#" onclick="bump(0);return false">Major</a> /
          <a href="#" onclick="bump(1);return false">Minor</a> /
          <a href="#" onclick="bump(2);return false">Patch</a> 올리기</span>
      </div>
      <label>업데이트 내용</label>
      <textarea id="deployMemo" placeholder="런처 화면에 표시됩니다. 예) 로그인 오류 수정, 통계 화면 속도 개선"></textarea>
    </div>
    <div class="row" style="margin-top:10px;justify-content:flex-end">
      <button type="button" class="secondary" onclick="clearPending()">비우기</button>
      <button type="button" id="deployBtn" onclick="deploy()">배포</button>
    </div>
    <div class="bar hidden" id="bar"><i></i></div>
    <div id="deployMsg" class="small-text" style="margin-top:8px"></div>
  </div>
</div>

<!-- 파일 목록 -->
<div class="card">
  <h2>배포 중인 파일 <span class="muted small-text">v<?= h($s['versionLabel'] ?: '-') ?></span></h2>
  <div class="row" style="margin-bottom:10px">
    <input type="text" id="filter" placeholder="파일 이름 검색" oninput="filterRows()" class="grow">
    <button type="button" class="danger" id="delBtn" onclick="deleteSelected()" disabled>선택 삭제</button>
    <label class="small-text"><input type="checkbox" id="removeOnPc" checked> PC에서도 삭제</label>
  </div>
  <div class="tablewrap">
  <table>
    <thead><tr><th><input type="checkbox" onclick="toggleAll(this)"></th><th>경로</th><th>크기</th><th class="hide-sm">SHA-256</th><th>올린 시각</th><th></th></tr></thead>
    <tbody id="rows">
    <?php foreach ($s['files'] as $path => $f): ?>
      <tr data-path="<?= h($path) ?>">
        <td><input type="checkbox" class="sel" value="<?= h($path) ?>" onchange="updateDelBtn()"></td>
        <td class="mono"><?= h($path) ?><?php if ($path === $s['launch']): ?><span class="tag">실행 파일</span><?php endif; ?></td>
        <td class="nowrap"><?= fmt_size($f['size']) ?></td>
        <td class="mono muted hide-sm" title="<?= h($f['sha256']) ?>"><?= h(substr($f['sha256'], 0, 10)) ?>…</td>
        <td class="muted small-text nowrap"><?= h(isset($f['uploaded']) ? $f['uploaded'] : '') ?></td>
        <td><button type="button" class="secondary small" onclick="replaceFile(this.closest('tr').dataset.path)">교체</button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$s['files']): ?><tr><td colspan="6" class="muted">아직 올린 파일이 없습니다.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($s['delete']): ?>
    <h2 style="margin-top:20px">PC에서 삭제될 파일</h2>
    <p class="muted small-text">아래 파일은 각 PC에서 다음 실행 때 bin 폴더에서 삭제됩니다.</p>
    <table><tbody>
    <?php foreach ($s['delete'] as $d): ?>
      <tr><td class="mono"><?= h($d) ?></td><td style="width:1%"><button type="button" class="secondary small" onclick='undelete(<?= h(json_encode($d, JSON_UNESCAPED_UNICODE)) ?>)'>삭제 취소</button></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  <?php endif; ?>
</div>

<!-- 배포 이력 -->
<div class="card">
  <h2>버전 / 배포 이력 <span class="muted small-text">최근 <?= HISTORY_KEEP ?>개 보관, 이 범위 안에서 롤백 가능</span></h2>
  <?php if (!$history): ?><p class="muted">이력이 없습니다.</p><?php else: ?>
  <div class="tablewrap">
  <table>
    <thead><tr><th>버전</th><th>빌드</th><th>시각</th><th>구분</th><th>업데이트 내용</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($history as $i => $hst):
        $n = count($hst['added']) + count($hst['updated']) + count($hst['deleted']); ?>
      <tr>
        <td class="nowrap"><b><?= h(!empty($hst['label']) ? 'v' . $hst['label'] : '-') ?></b><?php if ($i === 0): ?><span class="tag success">운영 중</span><?php endif; ?></td>
        <td class="muted"><?= h($hst['version']) ?></td>
        <td class="muted small-text nowrap"><?= h($hst['time']) ?></td>
        <td class="nowrap"><?= h(isset($typeLabel[$hst['type']]) ? $typeLabel[$hst['type']] : $hst['type']) ?></td>
        <td>
          <?= $hst['memo'] !== '' ? nl2br(h($hst['memo'])) : '<span class="muted">-</span>' ?>
          <?php if ($n): ?>
          <details><summary class="muted small-text">
            <?= $hst['added'] ? '추가 ' . count($hst['added']) . ' ' : '' ?><?= $hst['updated'] ? '교체 ' . count($hst['updated']) . ' ' : '' ?><?= $hst['deleted'] ? '삭제 ' . count($hst['deleted']) : '' ?>
          </summary>
            <ul class="hist-files mono">
              <?php foreach ($hst['added'] as $p): ?><li><span class="tag new">추가</span> <?= h($p) ?></li><?php endforeach; ?>
              <?php foreach ($hst['updated'] as $p): ?><li><span class="tag upd">교체</span> <?= h($p) ?></li><?php endforeach; ?>
              <?php foreach ($hst['deleted'] as $p): ?><li><span class="tag del">삭제</span> <?= h($p) ?></li><?php endforeach; ?>
            </ul>
          </details>
          <?php endif; ?>
        </td>
        <td><?php if ($i > 0 && !empty($hst['files'])): ?><button type="button" class="secondary small" onclick="rollback(<?= intval($hst['version']) ?>, <?= h(json_encode(!empty($hst['label']) ? $hst['label'] : '#' . $hst['version'])) ?>)">이 버전으로 롤백</button><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php script_common(); ?>
<script>
const APP = <?= json_encode($s['id']) ?>;
const CUR_LABEL = <?= json_encode($maxLabel) ?>;
const EXISTING = new Map(<?= json_encode(array_map(function ($p) { return [strtolower($p), $p]; }, array_keys($s['files'])), JSON_UNESCAPED_UNICODE) ?>);

function bump(i) {
  const p = (CUR_LABEL || '0.0.0').split('.').map(Number);
  p[i]++; for (let j = i + 1; j < 3; j++) p[j] = 0;
  deployLabel.value = p.join('.');
}

// ---------- 배포 대기 목록 ----------
const pending = new Map(); // 소문자 경로 -> {path, file}
function joinPath(prefix, p) {
  prefix = prefix.trim().replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
  return prefix ? prefix + '/' + p : p;
}
function addPending(items) {
  for (const it of items) {
    let path = it.path.replace(/\\/g, '/');
    const key = path.toLowerCase();
    if (EXISTING.has(key)) path = EXISTING.get(key);
    pending.set(key, {path, file: it.file});
  }
  renderPending();
}
function renderPending() {
  const tb = document.getElementById('pendingRows');
  document.getElementById('pendingBox').classList.toggle('hidden', !pending.size);
  tb.innerHTML = '';
  for (const [key, it] of [...pending].sort((a, b) => a[0].localeCompare(b[0]))) {
    const tr = document.createElement('tr');
    tr.innerHTML = '<td class="mono"></td><td class="nowrap"></td><td>' +
      (EXISTING.has(key) ? '<span class="tag upd">교체</span>' : '<span class="tag new">새 파일</span>') +
      '</td><td><button type="button" class="secondary small">빼기</button></td>';
    tr.children[0].textContent = it.path;
    tr.children[1].textContent = fmtSize(it.file.size);
    tr.querySelector('button').onclick = () => { pending.delete(key); renderPending(); };
    tb.appendChild(tr);
  }
  document.getElementById('deployBtn').textContent = '배포 (' + pending.size + '개 파일)';
}
function clearPending() { pending.clear(); renderPending(); }

function fromInput(input, isFolder) {
  const prefix = document.getElementById('prefix').value;
  const strip = isFolder && document.getElementById('stripTop').checked;
  addPending([...input.files].map(f => {
    let rel = (isFolder && f.webkitRelativePath) ? f.webkitRelativePath : f.name;
    if (strip && rel.includes('/')) rel = rel.substring(rel.indexOf('/') + 1);
    return {file: f, path: joinPath(prefix, rel)};
  }));
  input.value = '';
}
pickFiles.onchange = e => fromInput(e.target, false);
pickFolder.onchange = e => fromInput(e.target, true);

let replaceTarget = null;
function replaceFile(path) { replaceTarget = path; pickReplace.click(); }
pickReplace.onchange = e => {
  const f = e.target.files[0];
  if (f && replaceTarget) addPending([{file: f, path: replaceTarget}]);
  e.target.value = ''; replaceTarget = null;
  document.getElementById('pendingBox').scrollIntoView({behavior:'smooth', block:'center'});
};

const drop = document.getElementById('drop');
['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', async e => {
  const entries = [...e.dataTransfer.items].map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
  const prefix = document.getElementById('prefix').value;
  const strip = document.getElementById('stripTop').checked && entries.length === 1 && entries[0].isDirectory;
  const items = [];
  const readAll = async dir => { const r = dir.createReader(); let all = [], b; do { b = await new Promise((ok, no) => r.readEntries(ok, no)); all = all.concat(b); } while (b.length); return all; };
  async function walk(entry, base) {
    if (entry.isFile) { const file = await new Promise((ok, no) => entry.file(ok, no)); items.push({file, path: joinPath(prefix, base + file.name)}); }
    else if (entry.isDirectory) for (const c of await readAll(entry)) await walk(c, base + entry.name + '/');
  }
  if (strip) { for (const c of await readAll(entries[0])) await walk(c, ''); }
  else for (const en of entries) await walk(en, '');
  addPending(items);
});

// ---------- 배포: 파일 업로드 → 해시 확인 → 한 버전으로 확정 ----------
async function deploy() {
  if (!pending.size) return;
  const label = deployLabel.value.trim();
  if (!/^\d+\.\d+\.\d+$/.test(label)) { alert('버전은 1.2.3 형식으로 입력하세요.'); return; }
  const btn = document.getElementById('deployBtn'), msg = document.getElementById('deployMsg'), bar = document.getElementById('bar'), fill = bar.firstElementChild;
  btn.disabled = true; bar.classList.remove('hidden'); msg.className = 'small-text'; fill.style.width = '0';
  const list = [...pending.values()];
  const total = list.reduce((a, b) => a + b.file.size, 0) || 1;
  let done = 0;
  const items = [];
  try {
    for (let i = 0; i < list.length; i++) {
      const it = list[i];
      msg.textContent = '올리는 중 (' + (i + 1) + '/' + list.length + ') ' + it.path;
      const r = await api({action:'upload'}, it.file, p => fill.style.width = ((done + p * it.file.size) / total * 100) + '%');
      if (r.size !== it.file.size) throw new Error(it.path + ' 크기가 맞지 않습니다. 다시 시도하세요.');
      items.push({path: it.path, sha256: r.sha256, size: r.size});
      done += it.file.size;
    }
    msg.textContent = '배포 확정 중...';
    const r = await api({action:'deploy', app:APP, label, memo:deployMemo.value, items:JSON.stringify(items), deletes:'[]', removeOnPc:''});
    fill.style.width = '100%';
    if (!r.changed) { msg.className = 'small-text warn'; msg.textContent = '올린 파일이 모두 기존과 같아서 바뀐 것이 없습니다.'; btn.disabled = false; return; }
    msg.className = 'small-text ok';
    msg.textContent = 'v' + r.label + ' 배포 완료: 새 파일 ' + r.added + ', 교체 ' + r.updated + (r.same ? ', 변경 없음 ' + r.same : '');
    setTimeout(() => location.reload(), 1200);
  } catch (e) {
    msg.className = 'small-text err'; msg.textContent = '실패: ' + e.message + ' (아직 배포되지 않았습니다)';
    btn.disabled = false;
  }
}

// ---------- 목록 ----------
function selected() { return [...document.querySelectorAll('.sel:checked')].map(c => c.value); }
function updateDelBtn() { const n = selected().length, b = document.getElementById('delBtn'); b.disabled = !n; b.textContent = n ? '선택 삭제 (' + n + ')' : '선택 삭제'; }
function toggleAll(cb) { document.querySelectorAll('#rows tr:not([hidden]) .sel').forEach(c => c.checked = cb.checked); updateDelBtn(); }
function filterRows() {
  const q = document.getElementById('filter').value.toLowerCase();
  document.querySelectorAll('#rows tr[data-path]').forEach(tr => tr.hidden = !!q && !tr.dataset.path.toLowerCase().includes(q));
}
async function deleteSelected() {
  const paths = selected();
  if (!paths.length) return;
  const onPc = document.getElementById('removeOnPc').checked;
  const memo = prompt(paths.length + '개 파일을 삭제한 새 버전을 배포합니다.' + (onPc ? ' (각 PC에서도 삭제)' : '') + '\n업데이트 내용을 입력하세요', '파일 삭제: ' + paths.join(', ').slice(0, 80));
  if (memo === null) return;
  try { const r = await api({action:'deploy', app:APP, label:'', memo, items:'[]', deletes:JSON.stringify(paths), removeOnPc: onPc ? '1' : ''}); alert('v' + r.label + ' 로 배포되었습니다.'); location.reload(); }
  catch (e) { alert(e.message); }
}
async function undelete(path) {
  try { await api({action:'undelete', app:APP, path}); location.reload(); } catch (e) { alert(e.message); }
}
async function rollback(ver, label) {
  if (!confirm(label + ' 상태로 롤백할까요?\n새 빌드로 기록되며, 다음 실행 때 모든 PC에 반영됩니다.')) return;
  try { const r = await api({action:'rollback', app:APP, version:ver}); alert('v' + r.label + ' (빌드 ' + r.version + ') 로 롤백했습니다.'); location.reload(); }
  catch (e) { alert(e.message); }
}
</script>
