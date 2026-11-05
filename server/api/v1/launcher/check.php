<?php
// POST /api/v1/launcher/check  업데이트 확인 (+ PC 최초 등록)
require_once __DIR__ . '/../../../launcher_api.php';

$in = client_input();
$app = client_app($in);
$pcId = client_pc_id($in);
$row = touch_install($app, $pcId, $in);

$curBuild = intval(isset($in['currentBuild']) ? $in['currentBuild'] : 0);
$blocked = !empty($row['blocked']);
$active = !empty($app['active']);

json_out([
    'success'        => true,
    'solutionId'     => $app['id'],
    'name'           => $app['name'],
    'active'         => $active,
    'blocked'        => $blocked,
    'updateRequired' => $active && !$blocked && $curBuild !== intval($app['version']),
    'latestVersion'  => $app['versionLabel'] ?: (string)$app['version'],
    'latestBuild'    => intval($app['version']),
    'forceUpdate'    => false,
    'offlineRun'     => (bool)$app['offlineRun'],
    'manifestUrl'    => base_url() . 'manifests/' . rawurlencode($app['id']) . '.json',
    'filesUrl'       => base_url() . 'files/',
    'releaseNote'    => $app['releaseNote'],
    'notice'         => $app['notice'],
    'serverTime'     => now_str(),
]);
