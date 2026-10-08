<?php
/**
 * 前台用户登录
 *
 * 与后台共用 wm_login_locked() / wm_login_fail() / wm_login_reset()：
 * 按「账号 + IP」统计失败次数，达到上限后锁定；登录成功即清零。
 * 只有「账号存在且启用 + 密码错误」才计入失败，避免对任意用户名灌水撑爆限流表。
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/init.php';
require_once WM_INC . '/oauth.php';

if (wm_user() !== null) {
    wm_redirect('index.php');
}

$error = '';
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();

    $username = wm_input('username');
    $password = (string) ($_POST['password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username) || $password === '') {
        $error = '账号或密码错误';
    } else {
        // 与后台一致：按「账号 + IP」统计失败次数，仅失败才计数，成功即清零
        $wait = wm_login_locked($username);
        $user = null;
        $valid = false;

        if ($wait > 0) {
            $error = '登录失败次数过多，请在 ' . ceil($wait / 60) . ' 分钟后重试';
        } else {
            $user = wm_one(
                'SELECT * FROM ' . wm_t('user') . ' WHERE username = ? LIMIT 1',
                [$username]
            );
            $valid = $user !== null && password_verify($password, (string) $user['password']);

            if ($valid && (int) $user['status'] === 1) {
                wm_login_reset($username);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['user_pv'] = (string) $user['pass_version'];
                $_SESSION['user_active'] = time();
                $_SESSION['_csrf'] = wm_random(64);

                wm_exec(
                    'UPDATE ' . wm_t('user') . ' SET last_login_at = NOW(), last_login_ip = ?, login_count = login_count + 1 WHERE id = ?',
                    [wm_client_ip(), (int) $user['id']]
                );

                wm_redirect('index.php');
            }

            // 只有「账号存在且启用 + 密码错误」才计数；
            // 账号不存在或已禁用时不计数，避免对任意用户名灌水撑爆限流表
            if ($user !== null && (int) $user['status'] === 1 && !$valid) {
                wm_login_fail($username);
            }
            $error = $user !== null && (int) $user['status'] !== 1
                ? '账号已被禁用'
                : '账号或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>用户登录 - <?= e((string)wm_setting('site_name', '朋友圈')) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= e(WM_ASSET_VER) ?>">
    <?php wm_favicon_link('../'); ?>
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
</head>
<body class="login-body">
<div class="login-box">
    <div class="lb-head">
        <div class="lb-logo"><i data-lucide="user-round" aria-hidden="true"></i></div>
        <h1><?= e((string)wm_setting('site_name', '朋友圈')) ?></h1>
        <p>用户中心</p>
    </div>
    <?php
    // 一次性提示：聚合登录跳转 / 回调失败的原因经 wm_flash() 传来，
    // 本页原先只渲染局部 $error，会把这部分提示整个丢掉，用户将看不到失败原因。
    $flashHtml = wm_flash_html();
    ?>
    <?php if ($error !== ''): ?>
        <div class="alert err"><?= e($error) ?></div>
    <?php endif; ?>
    <?= $flashHtml ?>
    <form method="post" action="login.php" autocomplete="on">
        <?= wm_csrf_field() ?>
        <div class="lf">
            <span class="lf-ico"><i data-lucide="user-round" aria-hidden="true"></i></span>
            <input type="text" name="username" value="<?= e($username) ?>" placeholder="登录账号" maxlength="30" required autocomplete="username" autofocus>
        </div>
        <div class="lf">
            <span class="lf-ico"><i data-lucide="lock-keyhole" aria-hidden="true"></i></span>
            <input type="password" name="password" placeholder="登录密码" maxlength="72" required autocomplete="current-password">
        </div>
        <button class="lb-btn" type="submit">登 录</button>
    </form>
<?php
// 第三方聚合登录入口（后台「聚合登录」开启且数据表就绪时才显示）
// 注意：这里整条分支链都用替代语法（if(...) : ... elseif(...) : ... endif;），
// 不能写成 `if (...) { ... } elseif (...):` —— 括号语法与替代语法不可混用，会解析报错。
$oauthCfg = wm_oauth_cfg();
$oauthDefs = wm_oauth_methods();
if (wm_oauth_enabled() && wm_oauth_ready() && $oauthCfg['methods'] !== []):
    ?>
    <div class="lb-divider"><span>其他方式登录</span></div>
    <div class="oauth-btns">
        <?php foreach ($oauthCfg['methods'] as $mKey):
            if (!isset($oauthDefs[$mKey])) { continue; }
            $mDef = $oauthDefs[$mKey]; ?>
            <a class="oauth-btn" href="oauth.php?type=<?= e($mKey) ?>"
               style="--oc:<?= e($mDef['color']) ?>" rel="nofollow">
                <span class="ob-ico" aria-hidden="true"><?= (string)($mDef['icon'] ?? '') ?></span>
                <span class="ob-txt"><?= e($mDef['name']) ?>登录</span>
            </a>
        <?php endforeach; ?>
    </div>
<?php elseif ((string)wm_setting('oauth_on', '0') === '1'): ?>
    <div class="alert warn" style="margin:14px 0 0">聚合登录已开启，但配置不完整或数据库结构未升级，请联系管理员。</div>
<?php endif; ?>
    <div class="lb-foot">
        <?php if ((string) wm_setting('allow_register', '1') === '1'): ?>
            <a href="register.php">注册账号</a> ·
        <?php endif; ?>
        <a href="../index.php">返回前台</a>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.lucide) { window.lucide.createIcons({ attrs: { 'stroke-width': 1.8 } }); }
    });
</script>
</body>
</html>
