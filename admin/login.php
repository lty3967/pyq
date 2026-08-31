<?php
/**
 * 后台登录
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
}

if (wm_admin() !== null) {
    wm_redirect('index.php');
}

$err = '';
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $username = wm_input('username');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $err = '请输入账号和密码';
    } elseif (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) {
        $err = '账号或密码错误';
    } else {
        $wait = wm_login_locked($username);
        if ($wait > 0) {
            $err = '登录失败次数过多，请在 ' . ceil($wait / 60) . ' 分钟后重试';
        } else {
            $row = wm_one('SELECT * FROM ' . wm_t('admin') . ' WHERE username = ? LIMIT 1', [$username]);
            $hash = $row['password'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
            $valid = password_verify($password, (string)$hash);

            if ($row !== null && $valid && (int)$row['status'] === 1) {
                wm_login_reset($username);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$row['id'];
                $_SESSION['admin_pv'] = (string)$row['pass_version'];
                $_SESSION['admin_active'] = time();
                $_SESSION['_csrf'] = wm_random(64);

                if (password_needs_rehash((string)$row['password'], PASSWORD_DEFAULT)) {
                    wm_exec('UPDATE ' . wm_t('admin') . ' SET password = ? WHERE id = ?', [wm_password_hash($password), (int)$row['id']]);
                }
                wm_exec('UPDATE ' . wm_t('admin') . ' SET last_login_at = NOW(), last_login_ip = ?, login_count = login_count + 1 WHERE id = ?',
                    [wm_client_ip(), (int)$row['id']]);
                wm_log('登录', '登录成功', (int)$row['id']);
                wm_redirect('index.php');
            }

            wm_login_fail($username);
            wm_log('登录失败', '账号：' . $username, 0);
            if ($row !== null && (int)$row['status'] !== 1) {
                $err = '该账号已被禁用';
            } else {
                $err = '账号或密码错误';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>后台登录 - <?= e((string)wm_setting('site_name', '朋友圈')) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= e(WM_VERSION) ?>">
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
</head>
<body class="login-body">
<div class="login-box">
  <div class="lb-head">
    <div class="lb-logo"><i data-lucide="shield-check" aria-hidden="true"></i></div>
    <h1><?= e((string)wm_setting('site_name', '朋友圈')) ?></h1>
    <p>后台管理系统</p>
  </div>
  <?php if ($err !== ''): ?>
    <div class="alert err"><?= e($err) ?></div>
  <?php endif; ?>
  <form method="post" action="login.php" autocomplete="off">
    <?= wm_csrf_field() ?>
    <div class="lf">
      <span class="lf-ico"><i data-lucide="user-round" aria-hidden="true"></i></span>
      <input type="text" name="username" value="<?= e($username) ?>" placeholder="管理员账号" maxlength="20" required autofocus>
    </div>
    <div class="lf">
      <span class="lf-ico"><i data-lucide="lock-keyhole" aria-hidden="true"></i></span>
      <input type="password" name="password" placeholder="登录密码" maxlength="72" autocomplete="current-password" required>
    </div>
    <button class="lb-btn" type="submit">登 录</button>
  </form>
  <div class="lb-foot"><a href="../index.php">← 返回前台</a></div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.lucide) {
            window.lucide.createIcons({
                attrs: { 'stroke-width': 1.8 }
            });
        }
    });
</script>
</body>
</html>
