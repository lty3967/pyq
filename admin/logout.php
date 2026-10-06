<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';

// 仅 POST + CSRF 令牌才会真正登出；GET 只渲染确认页
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check(false);
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id > 0) { wm_log('退出登录', '', $id); }
    wm_admin_logout();
    wm_redirect('login.php');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>退出登录 - <?= e((string)wm_setting('site_name', '朋友圈')) ?></title>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= e(WM_VERSION) ?>">
</head>
<body class="login-body">
<div class="login-box">
  <div class="lb-head">
    <div class="lb-logo"><span style="font-size:26px">⏻</span></div>
    <h1>退出登录</h1>
    <p>确认要退出后台吗？</p>
  </div>
  <form method="post" action="logout.php">
    <?= wm_csrf_field() ?>
    <button class="lb-btn" type="submit">确认退出</button>
  </form>
  <div class="lb-foot"><a href="index.php">← 返回控制台</a></div>
</div>
</body>
</html>
