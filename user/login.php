<?php
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
    } elseif (!wm_rate_limit('user_login', 5, 900)) {
        $error = '登录失败次数过多，请稍后再试';
    } else {
        $user = wm_one(
            'SELECT * FROM ' . wm_t('user') . ' WHERE username = ? LIMIT 1',
            [$username]
        );

        $valid = $user !== null && password_verify($password, (string) $user['password']);
        if ($valid && (int) $user['status'] === 1) {
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

        $error = $user !== null && (int) $user['status'] !== 1
            ? '账号已被禁用'
            : '账号或密码错误';
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
        <a href="register.php">注册账号</a>
        <a href="../index.php">返回前台</a>
    </p>
</section>
</body>
</html>
