<?php
/**
 * 发起第三方聚合登录
 *
 * 这里不直接跳接口地址，而是先由本站后端请求
 *   {接口}?act=login&appid=..&appkey=..&type=..&redirect_uri=..
 * 拿到 JSON 里的 url（第三方授权地址），再把浏览器跳过去。
 * 直接跳接口是这类协议最常见的接错方式。
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/init.php';
require_once WM_INC . '/oauth.php';

$method = wm_input('type', 'GET', 'qq');
$cfg = wm_oauth_cfg();

// act=bind：已登录用户在「账号设置」发起的绑定流程。
// 回调后不换登录态，只把第三方标识绑到当前账号，
// 这样「用户名密码注册」的用户也能开通快捷登录。
$binding = (wm_input('act', 'GET', '') === 'bind');
$backUrl = $binding ? 'profile.php' : 'login.php';
$me = wm_user();

if (!$binding && $me !== null) {
    wm_redirect('index.php');
}
if ($binding && $me === null) {
    wm_flash(false, '请先登录本站账号，再绑定快捷登录');
    wm_redirect('login.php');
}

if (!wm_oauth_enabled()) {
    wm_flash(false, '聚合登录未开启或配置不完整，请联系管理员');
    wm_redirect($backUrl);
}

if (!in_array($method, $cfg['methods'], true)) {
    wm_flash(false, '该登录方式未开放');
    wm_redirect($backUrl);
}

if (!wm_rate_limit('oauth_start', 30, 600, 'ip' . wm_client_ip())) {
    wm_flash(false, '发起过于频繁，请稍后再试');
    wm_redirect($backUrl);
}

// 绑定模式下把「要绑给谁」记进 session，回调时据此入库而不是换登录态
if ($binding) {
    $_SESSION['_oauth_bind_uid'] = (int)$me['id'];
} else {
    unset($_SESSION['_oauth_bind_uid']);
}

$r = wm_oauth_authorize_url($method);
if (!$r['ok']) {
    wm_log('聚合登录跳转失败', $method . ' → ' . (string)$r['msg']);
    wm_flash(false, (string)$r['msg']);
    wm_redirect($backUrl);
}

// 跳的是接口返回的第三方授权地址
header('Location: ' . (string)$r['url']);
exit;