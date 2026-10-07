<?php
/**
 * 后台公共引导 + 布局函数
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/init.php';
require_once WM_INC . '/model.php';

// 后台禁止被搜索引擎索引 / 禁止缓存
if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

/** 当前脚本名（用于菜单高亮） */
function wm_cur(): string
{
    return basename((string)($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
}

/** 后台菜单定义 */
function wm_menu(): array
{
    return [
        ['title' => '概览', 'items' => [
            ['index.php', '控制台', 'layout-dashboard'],
        ]],
        ['title' => '内容', 'items' => [
            ['post_edit.php', '发布朋友圈', 'square-pen'],
            ['posts.php', '内容管理', 'notebook-pen'],
            ['categories.php', '分类管理', 'tags'],
            ['users.php', '用户管理', 'users-round'],
        ]],
        ['title' => '互动', 'items' => [
            ['comments.php', '评论管理', 'message-circle'],
            ['badwords.php', '违禁词设置', 'shield-ban'],
        ]],
        ['title' => '系统', 'items' => [
            ['profile.php', '管理员信息', 'user-round'],
            ['mail.php', '发信功能', 'send'],
            ['settings.php', '站点设置', 'settings-2'],
            ['update.php', '在线更新', 'refresh-cw'],
            ['logs.php', '操作日志', 'scroll-text'],
        ]],
    ];
}

/** 输出后台页面头部 */
function wm_head(string $pageTitle): void
{
    $admin = wm_admin();
    $siteName = (string)wm_setting('site_name', '朋友圈');
    $cur = wm_cur();
    $waitCmt = 0;
    try {
        $waitCmt = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('comment') . ' WHERE status = 0');
    } catch (Throwable $e) {
    }
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($pageTitle) ?> - 后台管理</title>
<link rel="stylesheet" href="../assets/css/admin.css?v=<?= e(WM_ASSET_VER) ?>">
<?php wm_favicon_link('../'); ?>
<script src="https://unpkg.com/lucide@0.468.0/dist/umd/lucide.min.js" defer></script>
</head>
<body>
<div class="layout">
  <aside class="side" id="side">
    <div class="brand">
            <span class="logo"><i data-lucide="orbit" aria-hidden="true"></i></span>
      <span class="bname"><?= e(wm_cut($siteName, 12)) ?></span>
    </div>
    <nav class="menu">
      <?php foreach (wm_menu() as $g): ?>
        <div class="mg"><?= e((string)$g['title']) ?></div>
        <?php foreach ($g['items'] as [$file, $label, $ico]): ?>
          <a class="mi <?= $cur === $file ? 'on' : '' ?>" href="<?= e($file) ?>">
            <span class="mico"><i data-lucide="<?= e($ico) ?>" aria-hidden="true"></i></span><span class="mtxt"><?= e($label) ?></span>
            <?php if ($file === 'comments.php' && $waitCmt > 0): ?><span class="badge"><?= $waitCmt > 99 ? '99+' : $waitCmt ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="mg">快捷</div>
      <a class="mi" href="../index.php" target="_blank" rel="noopener"><span class="mico"><i data-lucide="globe-2" aria-hidden="true"></i></span><span class="mtxt">查看前台</span></a>
      <a class="mi" href="logout.php"><span class="mico"><i data-lucide="log-out" aria-hidden="true"></i></span><span class="mtxt">退出登录</span></a>
    </nav>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-btn" type="button" id="menuBtn" aria-label="打开菜单"><i data-lucide="menu" aria-hidden="true"></i></button>
      <h1 class="ptitle"><?= e($pageTitle) ?></h1>
      <div class="tb-right">
        <span class="who"><i data-lucide="user-round" aria-hidden="true"></i> <?= e((string)($admin['nickname'] ?: $admin['username'])) ?></span>
        <a class="tb-link" href="logout.php">退出</a>
      </div>
    </header>
    <div class="content">
    <?php
    // 与前台用户中心共用同一套渲染逻辑
    echo wm_flash_html();
}

/** 输出后台页面尾部 */
function wm_foot(): void
{
    ?>
    </div>
    <footer class="pfoot">© 2026 朋友圈系统 v<?= e(WM_VERSION) ?> · <a href="https://www.770a.cn/" rel="nofollow">龙毅保留所有权利</a></footer>
  </div>
</div>
<div class="mask" id="mask"></div>
<script>window.WMA = <?= ejs(['token' => wm_csrf_token()]) ?>;</script>
<script src="../assets/js/admin.js?v=<?= e(WM_ASSET_VER) ?>"></script>
<script>document.addEventListener('DOMContentLoaded',function(){if(window.lucide){window.lucide.createIcons({attrs:{'stroke-width':1.8}});}});</script>
</body>
</html>
    <?php
}
