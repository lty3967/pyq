<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';
wm_user_logout();
wm_redirect('login.php');
