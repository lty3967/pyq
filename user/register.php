<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/init.php';

if (wm_user() !== null) {
    wm_redirect('index.php');
}

$error = '';
$form = [
    'username' => '',
    'nickname' => '',
    'email' => '',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();

    $form['username'] = wm_input('username');
    $form['nickname'] = wm_input('nickname');
    $form['email'] = wm_input('email');
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $form['username'])) {
        $error = '账号需为 3-30 位字母、数字或下划线';
    } elseif ($form['nickname'] === '' || mb_strlen($form['nickname']) > 20) {
        $error = '昵称需为 1-20 字';
    } elseif ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif ($password !== $password2) {
        $error = '两次密码不一致';
    } elseif (($weak = wm_password_weak($password)) !== '') {
        $error = $weak;
    } elseif (wm_one('SELECT id FROM ' . wm_t('user') . ' WHERE username = ? LIMIT 1', [$form['username']]) !== null) {
        $error = '账号已存在';
    } elseif (!wm_rate_limit('user_register', 3, 3600)) {
        $error = '注册过于频繁，请稍后再试';
    } else {
        try {
            wm_exec(
                'INSERT INTO ' . wm_t('user') . ' (username, password, nickname, email, status, pass_version, created_at)
                 VALUES (?, ?, ?, ?, 1, 1, NOW())',
                [$form['username'], wm_password_hash($password), $form['nickname'], $form['email']]
            );
            wm_flash(true, '注册成功，请登录');
            wm_redirect('login.php');
        } catch (Throwable $exception) {
            error_log('user register fail: ' . $exception->getMessage());
            $error = '注册失败，请稍后重试';
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>注册账号</title>
    <link rel="stylesheet" href="../assets/css/user.css?v=<?= e(WM_VERSION) ?>">
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
</head>
<body class="account-auth">
<section class="auth-box">
    <div class="auth-mark">我的朋友圈</div>
    <h1>注册账号</h1>

    <?php if ($error !== ''): ?>
        <div class="alert err"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="register.php" autocomplete="on">
        <?= wm_csrf_field() ?>

        <label>
            登录账号
            <input name="username" value="<?= e($form['username']) ?>" maxlength="30" required autocomplete="username">
        </label>

        <label>
            显示昵称
            <input name="nickname" value="<?= e($form['nickname']) ?>" maxlength="20" required>
        </label>

        <label>
            邮箱（选填）
            <input type="email" name="email" value="<?= e($form['email']) ?>" maxlength="120">
        </label>

        <label>
            密码
            <input type="password" name="password" maxlength="72" required autocomplete="new-password">
        </label>

        <label>
            确认密码
            <input type="password" name="password2" maxlength="72" required autocomplete="new-password">
        </label>

        <button class="primary" type="submit">创建账号</button>
    </form>

    <p class="auth-links">
        <a href="login.php">返回登录</a>
        <a href="../index.php">返回前台</a>
    </p>
</section>
</body>
</html>
