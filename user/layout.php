<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/init.php';
require WM_INC . '/model.php';

$user = wm_require_user();

function user_head(string $title): void
{
    global $user;

    $siteName = (string) wm_setting('site_name', '朋友圈');
    $displayName = (string) ($user['nickname'] ?: $user['username']);
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> - <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/user.css?v=<?= e(WM_VERSION) ?>">
</head>
<body>
<div class="user-shell">
    <header class="user-top">
        <a class="brand" href="../index.php"><?= e($siteName) ?></a>

        <nav class="user-nav" aria-label="用户菜单">
            <a href="index.php">我的动态</a>
            <a href="post.php">发布动态</a>
            <a href="comments.php">收到的评论</a>
            <a href="profile.php"><?= e($displayName) ?></a>
            <a href="logout.php">退出</a>
        </nav>
    </header>

    <main class="user-main">
        <div class="page-head">
            <h1><?= e($title) ?></h1>
            <a class="back" href="../index.php">查看前台</a>
        </div>
<?php
}

function user_foot(): void
{
    ?>
    </main>

    <footer class="user-foot">
        个人中心 · <a href="../index.php">返回前台</a>
    </footer>
</div>
</body>
</html>
<?php
}

function user_flash(): void
{
    if (empty($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return;
    }

    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);

    $class = !empty($flash['ok']) ? 'ok' : 'err';
    echo '<div class="alert ' . $class . '">' . e((string) $flash['msg']) . '</div>';
}
