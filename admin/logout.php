<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';
$id = (int)($_SESSION['admin_id'] ?? 0);
if ($id > 0) { wm_log('退出登录', '', $id); }
wm_admin_logout();
wm_redirect('login.php');
