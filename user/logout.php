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
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= e(WM_ASSET_VER) ?>">
    <link rel="stylesheet" href="../assets/css/user.css?v=<?= e(WM_ASSET_VER) ?>">
    <?php if (function_exists('wm_favicon_link')) { wm_favicon_link('../'); } ?>
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
</head>
<body class="login-body">
<div class="login-box">
    <div class="lb-head">
        <div class="lb-logo"><i data-lucide="log-out" aria-hidden="true"></i></div>
        <h1>退出登录</h1>
        <p>确认要退出当前账号吗？</p>
    </div>

    <form method="post" action="logout.php">
        <?= wm_csrf_field() ?>
        <button class="lb-btn" type="submit">确认退出</button>
    </form>

    <div class="lb-foot">
        <a href="index.php">返回个人中心</a> ·
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
