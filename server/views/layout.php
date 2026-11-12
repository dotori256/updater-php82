<?php if (!defined('APP_ROOT')) exit;

function page_head($title) { ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?></title>
<style>
  :root { --bg:#f5f6f8; --card:#fff; --text:#1d2330; --muted:#6b7385; --line:#e3e6ec; --accent:#2f6fed; --accent-bg:#eef3ff; --danger:#d64545; --ok:#1f9d55; --warn:#b7791f; --nav:#1d2330; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,"Malgun Gothic","Apple SD Gothic Neo",sans-serif; background:var(--bg); color:var(--text); font-size:14px; }
  header { background:var(--nav); color:#fff; padding:0 20px; display:flex; justify-content:space-between; align-items:center; gap:12px; min-height:52px; flex-wrap:wrap; }
  header h1 { font-size:16px; margin:0; font-weight:600; }
  header a { color:#cfd6e4; text-decoration:none; }
  header nav { display:flex; gap:4px; flex-wrap:wrap; }
  header nav a { padding:16px 12px; display:block; }
  header nav a.on { color:#fff; box-shadow:inset 0 -3px 0 var(--accent); }
  main { max-width:1200px; margin:20px auto; padding:0 16px; }
  a { color:var(--accent); }
  .card { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:18px; margin-bottom:16px; }
  .card h2 { font-size:15px; margin:0 0 12px; display:flex; justify-content:space-between; align-items:center; gap:8px; flex-wrap:wrap; }
  .row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  .grow { flex:1; min-width:180px; }
  label { white-space:nowrap; }
  input[type=text], input[type=password], select, textarea { padding:7px 9px; border:1px solid var(--line); border-radius:5px; font-size:14px; background:#fff; color:var(--text); font-family:inherit; }
  textarea { width:100%; min-height:64px; resize:vertical; }
  button, .btn { white-space:nowrap; display:inline-block; padding:7px 14px; border:1px solid var(--accent); background:var(--accent); color:#fff; border-radius:5px; cursor:pointer; font-size:14px; text-decoration:none; }
  button.secondary, .btn.secondary { background:#fff; color:var(--accent); }
  button.danger { background:var(--danger); border-color:var(--danger); }
  button.small, .btn.small { padding:3px 9px; font-size:12px; }
  button:disabled { opacity:.5; cursor:default; }
  table { width:100%; border-collapse:collapse; }
  th, td { text-align:left; padding:7px 8px; border-bottom:1px solid var(--line); vertical-align:middle; }
  th { color:var(--muted); font-weight:600; font-size:12px; white-space:nowrap; }
  .mono { font-family:Consolas,monospace; font-size:12px; word-break:break-all; }
  .muted { color:var(--muted); }
  .small-text { font-size:12px; }
  .nowrap { white-space:nowrap; }
  .stats { display:flex; gap:28px; flex-wrap:wrap; }
  .stats div b { display:block; font-size:20px; }
  .tiles { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:12px; margin-bottom:16px; }
  .tile { background:var(--card); border:1px solid var(--line); border-radius:8px; padding:14px; text-decoration:none; color:var(--text); display:block; }
  .tile span { color:var(--muted); font-size:12px; }
  .tile b { display:block; font-size:24px; margin-top:4px; }
  a.tile:hover { border-color:var(--accent); }
  .drop { border:2px dashed var(--line); border-radius:8px; padding:20px; text-align:center; color:var(--muted); margin-bottom:12px; }
  .drop.over { border-color:var(--accent); background:var(--accent-bg); }
  .ok { color:var(--ok); } .err { color:var(--danger); } .warn { color:var(--warn); }
  .bar { height:8px; background:var(--line); border-radius:4px; overflow:hidden; margin-top:10px; }
  .bar i { display:block; height:100%; width:0; background:var(--accent); transition:width .2s; }
  .meter { height:6px; background:var(--line); border-radius:3px; overflow:hidden; margin-top:6px; }
  .meter i { display:block; height:100%; background:var(--ok); }
  pre { background:#f0f2f5; padding:10px; border-radius:5px; overflow:auto; font-size:12px; margin:8px 0; }
  .tag { display:inline-block; font-size:11px; padding:1px 6px; border-radius:3px; background:var(--accent-bg); color:var(--accent); margin-left:4px; white-space:nowrap; }
  .tag.new, .tag.success { background:#e6f6ec; color:var(--ok); }
  .tag.upd, .tag.old, .tag.recovered { background:#fff4e0; color:var(--warn); }
  .tag.del, .tag.fail, .tag.blocked { background:#fdeaea; color:var(--danger); }
  .tag.gray, .tag.offline, .tag.uptodate { background:#eef0f3; color:var(--muted); }
  .tablewrap { overflow-x:auto; }
  .apps { display:grid; grid-template-columns:repeat(auto-fill, minmax(270px, 1fr)); gap:14px; }
  .app { display:block; background:var(--card); border:1px solid var(--line); border-radius:8px; padding:16px; color:var(--text); text-decoration:none; }
  .app:hover { border-color:var(--accent); }
  .app h3 { margin:0 0 2px; font-size:16px; }
  .app .meta { display:flex; gap:16px; margin-top:12px; font-size:12px; color:var(--muted); }
  .app .meta b { display:block; font-size:15px; color:var(--text); }
  .login { max-width:340px; margin:80px auto; }
  details summary { cursor:pointer; }
  .hist-files { margin:6px 0 0; padding-left:18px; font-size:12px; }
  .subnav { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
  .subnav a { padding:7px 14px; border-radius:5px; text-decoration:none; color:var(--text); background:var(--card); border:1px solid var(--line); }
  .subnav a.on { background:var(--accent); border-color:var(--accent); color:#fff; }
  .filters a { margin-right:10px; text-decoration:none; }
  .filters a.on { font-weight:700; text-decoration:underline; }
  .hidden { display:none !important; }
  .form-grid { display:grid; grid-template-columns:140px 1fr; gap:10px 14px; align-items:center; }
  .form-grid > label { color:var(--muted); }
  dialog.stepup { border:1px solid var(--line); border-radius:10px; padding:20px; width:min(760px, calc(100vw - 32px)); max-height:calc(100vh - 40px); overflow:auto; color:var(--text); background:var(--card); }
  dialog.stepup::backdrop { background:rgba(20,24,33,.55); }
  dialog.stepup h2 { font-size:16px; margin:0 0 12px; }
  dialog.stepup .su-code { width:100%; font-size:18px; letter-spacing:4px; padding:9px 11px; }
  .stepup-body { max-height:50vh; overflow:auto; margin-bottom:8px; }
  .stepup-body table { font-size:12px; }
  .stepup-lock { background:var(--accent-bg); padding:8px 10px; border-radius:6px; margin:12px 0 8px; }
  .stepup-check { white-space:normal; }
  .warnbox { background:#fff4e0; color:#7a4f0e; padding:8px 10px; border-radius:6px; margin:8px 0; }
  @media (max-width:640px) { .hide-sm { display:none; } .form-grid { grid-template-columns:1fr; } }
</style>
</head>
<body>
<?php }

function page_header($active, $crumb = null) { ?>
<header>
  <h1><a href="./" style="color:#fff"><?= h(SITE_TITLE) ?></a><?php if ($crumb): ?> <span style="color:#8d97ab;margin:0 4px">›</span> <?= h($crumb) ?><?php endif; ?></h1>
  <nav>
    <a href="./" class="<?= $active === 'dash' ? 'on' : '' ?>">대시보드</a>
    <a href="?page=logs" class="<?= $active === 'logs' ? 'on' : '' ?>">업데이트 이력</a>
    <a href="?page=check" class="<?= $active === 'check' ? 'on' : '' ?>">설치 점검</a>
    <a href="?page=security" class="<?= $active === 'security' ? 'on' : '' ?>">보안</a>
    <a href="?logout=1">로그아웃</a>
  </nav>
</header>
<?php }

function result_tag($r) {
    $map = ['success' => '성공', 'fail' => '실패', 'uptodate' => '최신', 'recovered' => '복구됨', 'offline' => '오프라인 실행', 'blocked' => '차단', 'checking' => '확인 중'];
    return '<span class="tag ' . h($r) . '">' . h(isset($map[$r]) ? $map[$r] : ($r ?: '-')) . '</span>';
}

function script_common() { ?>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
const STEPUP = <?= json_encode(stepup_kind()) ?>;
<?php include __DIR__ . '/common.js.php'; ?>
</script>
<?php }
