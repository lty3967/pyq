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
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>用户登录</title>
    <link rel="stylesheet" href="../assets/css/user.css?v=<?= e(WM_VERSION) ?>">
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
</head>
<body class="account-auth">
<section class="auth-box">
    <div class="auth-mark">我的朋友圈</div>
    <h1>用户登录</h1>

    <?php if ($error !== ''): ?>
        <div class="alert err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" autocomplete="on">
        <?= wm_csrf_field() ?>

        <label>
            账号
            <input name="username" value="<?= e($username) ?>" maxlength="30" required autocomplete="username">
        </label>

        <label>
            密码
            <input type="password" name="password" maxlength="72" required autocomplete="current-password">
        </label>

        <button class="primary" type="submit">登录</button>
    </form>

    <p class="auth-links">
        <?php if ((string) wm_setting('allow_register', '1') === '1'): ?>
            <a href="register.php">注册账号</a>
        <?php endif; ?>
        <a href="../index.php">返回前台</a>
    </p>
</section>
</body>
</html>
