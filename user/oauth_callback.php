<?php
/**
 * 第三方聚合登录回调
 *
 * 服务商会把浏览器带回到这里，query 形如：
 *   ?wm_state=<本站随机串>&type=qq&code=<第三方授权码>
 *
 * 处理顺序：
 *   1. 校验随机串（替代该协议缺失的 state，做 CSRF 防护）
 *   2. 取回本次发起的登录方式（以 session 为准，不信任回调里的 type）
 *   3. 用 code 调接口换 social_uid / nickname / faceimg
 *   4. 登录或自动注册
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/init.php';
require_once WM_INC . '/oauth.php';

$error = '';

// 随机串取出即失效：一次性、可重放攻击直接失效
$expected = wm_oauth_take_state();
$inState = wm_input('wm_state', 'GET', '');
if ($inState === '') {
    // 少数服务商支持并回传标准 state，一并接受
    $inState = wm_input('state', 'GET', '');
}
if ($expected === '') {
    $error = '登录会话已失效，请返回登录页重新发起';
} elseif (!hash_equals($expected, $inState)) {
    $error = '登录校验失败，请返回登录页重新发起';
}

// 以 session 中记录的发起方式为准，回调里的 type 仅作兜底
$method = wm_oauth_get_method();
if ($method === '' && $error === '') {
    $method = wm_oauth_provider_key(wm_input('type', 'GET', ''));
}

$code = wm_input('code', 'GET', '');

if ($error === '' && $method === '') {
    $error = '无法识别登录方式，请返回登录页重新发起';
}

if ($error === '') {
    $r = wm_oauth_fetch_user($method, $code);
    if (!$r['ok']) {
        $error = (string)$r['msg'];
        error_log('oauth fetch user fail: ' . $error);
    } else {
        $ou = $r['user'];

        // 绑定模式：把第三方标识绑到发起绑定的那个账号上，不换登录态
        $bindUid = (int)($_SESSION['_oauth_bind_uid'] ?? 0);
        unset($_SESSION['_oauth_bind_uid']);
        if ($bindUid > 0) {
            $b = wm_oauth_bind($bindUid, (string)$ou['provider'], (string)$ou['openid'], (string)$ou['nickname'], (string)$ou['avatar']);
            wm_log('绑定快捷登录', $b['ok'] ? '成功' : '失败：' . (string)$b['msg']);
            wm_flash((bool)$b['ok'], (string)$b['msg']);
            wm_redirect('profile.php');
        }

        $res = wm_oauth_login(
            (string)$ou['provider'],
            (string)$ou['openid'],
            (string)$ou['nickname'],
            (string)$ou['avatar']
        );
        if (!$res['ok'] || empty($res['user'])) {
            $error = (string)$res['msg'];
        } else {
            $u = $res['user'];
            $isNew = !empty($res['new']);
            wm_oauth_sign_in($u);
            wm_log('聚合登录', ($isNew ? '新用户注册并登录：' : '登录成功：')
                . (string)$u['username'] . '（' . (string)$ou['provider'] . '）');
            wm_flash(true, $isNew ? '欢迎你，已为你自动创建账号' : '登录成功');
            wm_redirect('index.php');
        }
    }
}

// 失败原因一定要回显，否则用户只会看到「被弹回登录页」而不知发生了什么
if ($error !== '') {
    wm_flash(false, $error);
}
wm_redirect('login.php');