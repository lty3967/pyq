<?php
/**
 * 定时检测更新（cron 入口）
 *
 * 用途：配合宝塔面板「计划任务 → 访问URL」实现每天自动检测新版本，
 * 无需打开后台页面才触发检测。建议每天凌晨执行一次，例如：
 *     0 4 * * *
 *
 * 用法（把域名与 key 换成后台「在线更新」页显示的完整地址）：
 *     https://你的站点/cron_update.php?key=xxxxxxxx
 *
 * 安全：key 与后台显示的 cron_key 一致才执行；一小时内的重复请求会被
 * 节流跳过，避免地址泄露后被恶意刷远程请求。
 *
 * 返回 JSON：
 *     {"ok":true,"has_update":false,"latest":"1.3.4","current":"1.3.4",...}
 */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';
require_once WM_INC . '/update.php';
require_once WM_INC . '/mailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** 输出 JSON 并结束 */
function wm_cron_json(bool $ok, string $msg, array $data = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'msg' => $msg] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 1) 密钥校验：与后台「在线更新」页显示的 key 一致才放行
$key = (string)($_GET['key'] ?? '');
$expect = wm_update_cron_key();
if ($expect === '' || $key === '' || !hash_equals($expect, $key)) {
    wm_cron_json(false, '密钥不正确，请使用后台「在线更新」页显示的完整计划任务地址', [], 403);
}

// 2) 更新通道未配置时无法检测
$channel = wm_update_channel();
if ($channel === '') {
    wm_cron_json(false, '未配置更新通道（update_channel），无法检测更新');
}

// 3) 节流：一小时内重复访问直接跳过（正常每天一次不会命中）
$last = (int)wm_setting('update_checked_at', '0');
$now = time();
if ($last > 0 && ($now - $last) < 3600) {
    wm_cron_json(true, '未到检测时间（一小时内已检测过），本次跳过', [
        'skipped' => true,
        'last_check' => date('Y-m-d H:i:s', $last),
    ]);
}

// 先落时间戳再请求：即使本次远程请求失败，也不会因重复访问形成高频请求
wm_setting_set('update_checked_at', (string)$now);

// 4) 拉取更新清单并比对版本
$m = wm_update_fetch_manifest($channel, 20);
if (!$m['ok']) {
    error_log('cron update check fail: ' . (string)$m['msg']);
    wm_cron_json(false, '检测失败：' . (string)$m['msg']);
}

$latest = (string)($m['data']['version'] ?? '');
$current = WM_VERSION;
$has = $latest !== '' && version_compare($latest, $current, '>');
$min = (string)($m['data']['min_version'] ?? '');
$tooLow = $min !== '' && version_compare($current, $min, '<');

// 5) 检测到新版本：按后台开关邮件通知站长（同一版本只发一次，见 wm_notify_update）
$mailed = null;
if ($has && (string)wm_setting('notify_on_update', '1') === '1') {
    $r = wm_notify_update($latest, $current, (string)($m['data']['time'] ?? ''), (string)($m['data']['changelog'] ?? ''), 0);
    $mailed = (bool)$r['ok'];
}

wm_cron_json(true, $has ? "发现新版本 V{$latest}（当前 V{$current}）" : "当前已是最新版本（V{$current}）", [
    'has_update' => $has,
    'latest' => $latest,
    'current' => $current,
    'too_low' => $tooLow,
    'min_version' => $min,
    'time' => (string)($m['data']['time'] ?? ''),
    'changelog' => (string)($m['data']['changelog'] ?? ''),
    'mailed' => $mailed,
    'check_time' => date('Y-m-d H:i:s', $now),
]);
