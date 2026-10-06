<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';

// 仅 POST + CSRF 令牌才会真正登出；GET 只渲染确认页，
// 避免 <img src="logout.php"> 之类的跨站请求把用户强制登出
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check(false);
    wm_user_logout();
    wm_redirect('login.php');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>退出登录</title>
    <link rel="stylesheet" href="../assets/css/user.css?v=<?= e(WM_VERSION) ?>">
</head>
<body class="account-auth">
<section class="auth-box">
    <div class="auth-mark">我的朋友圈</div>
    <h1>退出登录</h1>
    <p class="muted">确认要退出当前账号吗？</p>

    <form method="post" action="logout.php">
        <?= wm_csrf_field() ?>
        <button class="primary" type="submit">确认退出</button>
    </form>

    <p class="auth-links">
        <a href="index.php">返回个人中心</a>
        <a href="../index.php">返回前台</a>
    </p>
</section>
</body>
</html>
