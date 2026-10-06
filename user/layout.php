<?php
/**
 * 用户中心公共引导 + 页面骨架
 *
 * 与后台（admin/inc/layout.php）共用同一套视觉：
 *   - 加载 admin.css 作为基础样式（侧边栏 / 顶栏 / 卡片 / 表单等组件一致）；
 *   - 内容区组件（.panel/.stat-grid/.two-col/.form/.avatar-editor/.upload-box…）
 *     仍由 user.css 提供，仅做内容排版，不重复定义外壳样式；
 *   - 侧边菜单 + 顶栏交互复用 assets/js/admin.js 的菜单开关逻辑。
 *
 * 所有 user/*.php（除登录/注册/退出）都先 require 本文件：
 *   1. 加载 includes/init.php（常量、配置、数据库、会话）
 *   2. wm_require_user() 校验登录态，未登录直接跳转登录页
 *   3. 提供 user_head() / user_foot() / user_flash() 三个布局函数
 * 页面内通过 global $user 获取当前登录用户数组。
 */
declare(strict_types=1);

require dirname(__DIR__) . '/includes/init.php';
require WM_INC . '/model.php';

$user = wm_require_user();

/** 当前脚本名（用于菜单高亮） */
function user_cur(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}

/** 用户中心菜单定义 */
function user_menu(): array
{
    return [
        ['index.php', '我的动态', 'layout-list'],
        ['post.php', '发布动态', 'square-pen'],
        ['comments.php', '收到的评论', 'message-circle'],
        ['profile.php', '账号设置', 'settings-2'],
    ];
}

/** 输出用户中心页面头部 */
function user_head(string $title): void
{
    global $user;

    $siteName = (string) wm_setting('site_name', '朋友圈');
    $displayName = (string) ($user['nickname'] ?: $user['username']);
    $cur = user_cur();
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title) ?> - <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= e(WM_ASSET_VER) ?>">
    <link rel="stylesheet" href="../assets/css/user.css?v=<?= e(WM_ASSET_VER) ?>">
    <?php wm_favicon_link('../'); ?>
    <script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
</head>
<body>
<div class="layout">
    <aside class="side" id="side">
        <div class="brand">
            <span class="logo"><i data-lucide="user-round" aria-hidden="true"></i></span>
            <span class="bname"><?= e(wm_cut($siteName, 12)) ?></span>
        </div>
        <nav class="menu">
            <?php foreach (user_menu() as [$file, $label, $ico]): ?>
                <a class="mi <?= $cur === $file ? 'on' : '' ?>" href="<?= e($file) ?>">
                    <span class="mico"><i data-lucide="<?= e($ico) ?>" aria-hidden="true"></i></span><span class="mtxt"><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
            <div class="mg">快捷</div>
            <a class="mi" href="../index.php" target="_blank" rel="noopener"><span class="mico"><i data-lucide="globe-2" aria-hidden="true"></i></span><span class="mtxt">查看前台</span></a>
            <a class="mi" href="logout.php"><span class="mico"><i data-lucide="log-out" aria-hidden="true"></i></span><span class="mtxt">退出登录</span></a>
        </nav>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="menu-btn" type="button" id="menuBtn" aria-label="打开菜单"><i data-lucide="menu" aria-hidden="true"></i></button>
            <h1 class="ptitle"><?= e($title) ?></h1>
            <div class="tb-right">
                <span class="who"><i data-lucide="user-round" aria-hidden="true"></i> <?= e($displayName) ?></span>
                <a class="tb-link" href="logout.php">退出</a>
            </div>
        </header>
        <div class="content">
        <?php
        // 与后台共用同一套渲染逻辑
        echo wm_flash_html();
}

/** 输出用户中心页面尾部 */
function user_foot(): void
{
    ?>
        </div>
        <footer class="pfoot">© 2026 <?= e((string)wm_setting('site_name', '朋友圈')) ?> · <a href="../index.php">返回前台</a></footer>
    </div>
</div>
<div class="mask" id="mask"></div>
<script src="../assets/js/admin.js?v=<?= e(WM_ASSET_VER) ?>"></script>
<script>document.addEventListener('DOMContentLoaded',function(){if(window.lucide){window.lucide.createIcons({attrs:{'stroke-width':1.8}});}});</script>
</body>
</html>
<?php
}

function user_flash(): void
{
    // 与后台共用同一套渲染逻辑
    echo wm_flash_html();
}
