<?php
/**
 * 前台首页：微信朋友圈风格时间线
 */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';
require WM_INC . '/model.php';

$catId = wm_input_int('cat', 'GET', 0);
$page = max(1, wm_input_int('page', 'GET', 1));
$size = max(5, min(30, (int)wm_setting('page_size', '10')));

$cats = wm_categories(true);
$catCounts = wm_category_counts();
$curCat = null;
foreach ($cats as $c) {
    if ((int)$c['id'] === $catId) { $curCat = $c; }
}
if ($catId > 0 && $curCat === null) { $catId = 0; }

$list = wm_post_list($page, $size, $catId, false);

$siteName = (string)wm_setting('site_name', '我的朋友圈');
$ownerName = (string) wm_setting('owner_name', '站长');
$ownerSign = (string) wm_setting('owner_signature', '');
$ownerAvatar = (string) wm_setting('owner_avatar', '');
$currentUser = wm_user();
if ($currentUser !== null) {
    $ownerName = (string) ($currentUser['nickname'] ?: $currentUser['username']);
    $ownerSign = (string) ($currentUser['signature'] ?? '');
    $ownerAvatar = (string) ($currentUser['avatar'] ?? '');
}
$cover = (string)wm_setting('cover_image', '');
$allowLike = (string)wm_setting('allow_like', '1') === '1';
$allowComment = (string)wm_setting('allow_comment', '1') === '1';
$needAudit = (string)wm_setting('comment_need_audit', '1') === '1';
$base = wm_base_url();

/** 头像占位 */
function wm_avatar_html(string $name, string $avatar, string $cls = 'avatar'): string
{
    // 统一走路径白名单（旧正则允许 ..，存在被指向其他目录的可能）
    if ($avatar !== '' && wm_safe_display_path($avatar) !== '') {
        return '<img class="' . e($cls) . '" src="' . e($avatar) . '" alt="" loading="lazy">';
    }
    $ch = mb_substr($name !== '' ? $name : '友', 0, 1);
    $colors = ['#07c160', '#1989fa', '#ff976a', '#7232dd', '#ee0a24', '#00b8d4', '#fa8c16'];
    $idx = abs(crc32($name)) % count($colors);
    return '<span class="' . e($cls) . ' ph" style="background:' . $colors[$idx] . '">' . e($ch) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#ededed">
<title><?= e($siteName) ?><?= $curCat ? ' - ' . e((string)$curCat['name']) : '' ?></title>
<meta name="description" content="<?= e(wm_cut((string)wm_setting('site_desc', ''), 100)) ?>">
<link rel="stylesheet" href="assets/css/style.css?v=<?= e(WM_VERSION) ?>">
</head>
<body>
<div class="app">

  <!-- 顶部封面 -->
  <header class="cover">
    <?php if ($cover !== '' && wm_safe_display_path($cover) !== ''): ?>
      <img class="cover-img" src="<?= e($cover) ?>" alt="">
    <?php else: ?>
      <div class="cover-img cover-default"></div>
    <?php endif; ?>
    <div class="cover-mask"></div>
    <a class="cover-back" href="<?= e($base) ?>" aria-label="返回首页">‹</a>
    <a class="cover-user" href="<?= $currentUser !== null ? 'user/profile.php' : 'user/login.php' ?>" aria-label="<?= $currentUser !== null ? '打开个人资料' : '登录账号' ?>">
      <div class="cu-text">
        <div class="cu-name"><?= e($ownerName) ?></div>
        <?php if ($ownerSign !== ''): ?><div class="cu-sign"><?= e(wm_cut($ownerSign, 40)) ?></div><?php endif; ?>
      </div>
      <?= wm_avatar_html($ownerName, $ownerAvatar, 'cu-avatar') ?>
    </a>
  </header>

  <!-- 分类横向筛选 -->
  <nav class="cats" aria-label="分类">
    <a class="cat <?= $catId === 0 ? 'on' : '' ?>" href="index.php">全部</a>
    <?php foreach ($cats as $c):
        $cid = (int)$c['id']; ?>
      <a class="cat <?= $catId === $cid ? 'on' : '' ?>" href="index.php?cat=<?= $cid ?>"
         style="<?= $catId === $cid ? 'background:' . e((string)$c['color']) . ';border-color:' . e((string)$c['color']) : '' ?>">
        <?= e((string)$c['name']) ?><i><?= (int)($catCounts[$cid] ?? 0) ?></i>
      </a>
    <?php endforeach; ?>
  </nav>

  <!-- 时间线 -->
  <main class="feed" id="feed">
    <?php if (!$list['rows']): ?>
      <div class="empty">
        <div class="empty-ico">📭</div>
        <p>还没有内容</p>
      </div>
    <?php endif; ?>

    <?php foreach ($list['rows'] as $p):
      $pid = (int)$p['id'];
      $media = $p['media'];
      $imgs = array_values(array_filter($media, static fn($m) => $m['type'] === 'image'));
      $vids = array_values(array_filter($media, static fn($m) => $m['type'] === 'video'));
      $gridCls = 'g' . min(9, count($imgs));
    ?>
    <article class="item" data-id="<?= $pid ?>">
      <?= wm_avatar_html((string)($p['author'] ?: $ownerName), (string)($p['author_avatar'] ?? ''), 'avatar') ?>
      <div class="body">
        <div class="name"><?= e((string)($p['author'] ?: $ownerName)) ?><?php if ((int)$p['is_top'] === 1): ?><span class="tag-top">置顶</span><?php endif; ?></div>

        <?php if ((string)$p['content'] !== ''): ?>
          <div class="text"><?= wm_text_html((string)$p['content']) ?></div>
        <?php endif; ?>

        <?php if ($imgs): ?>
          <div class="grid <?= $gridCls ?>">
            <?php foreach ($imgs as $i => $m):
              if ($i >= 9) { break; }
              $src = $m['thumb'] !== '' ? (string)$m['thumb'] : (string)$m['path']; ?>
              <div class="cell">
                <img src="<?= e($src) ?>" data-full="<?= e((string)$m['path']) ?>" alt="" loading="lazy"
                     <?= count($imgs) === 1 && (int)$m['width'] > 0 ? 'class="single"' : '' ?>>
                <?php if ($i === 8 && count($imgs) > 9): ?><span class="more">+<?= count($imgs) - 9 ?></span><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php foreach ($vids as $m): ?>
          <div class="video-box">
            <video src="<?= e((string)$m['path']) ?>" controls preload="metadata" playsinline
                   <?= $m['thumb'] !== '' ? 'poster="' . e((string)$m['thumb']) . '"' : '' ?>></video>
          </div>
        <?php endforeach; ?>

        <?php if (!empty($p['location'])): ?>
          <div class="loc">📍 <?= e((string)$p['location']) ?></div>
        <?php endif; ?>

        <div class="meta">
          <span class="time"><?= e(wm_time_ago($p['created_at'])) ?></span>
          <?php if (!empty($p['cat_name'])): ?>
            <a class="cat-tag" href="index.php?cat=<?= (int)$p['cat_id'] ?>" style="color:<?= e((string)$p['cat_color']) ?>">#<?= e((string)$p['cat_name']) ?></a>
          <?php endif; ?>
          <span class="views" title="浏览量"><?= (int)$p['views'] ?> 次浏览</span>
          <button class="act-btn" type="button" data-act="panel" aria-label="操作">•••</button>
        </div>

        <div class="panel" hidden>
          <?php if ($allowLike): ?>
            <button type="button" class="p-btn like-btn <?= !empty($p['liked']) ? 'on' : '' ?>" data-act="like" data-id="<?= $pid ?>">
              <span class="ico">♥</span><span class="txt"><?= !empty($p['liked']) ? '取消' : '赞' ?></span>
            </button>
          <?php endif; ?>
          <?php if ($allowComment && (int)$p['allow_comment'] === 1): ?>
            <button type="button" class="p-btn" data-act="comment" data-id="<?= $pid ?>"><span class="ico">💬</span><span class="txt">评论</span></button>
          <?php endif; ?>
        </div>

        <?php $hasLike = (int)$p['likes'] > 0; $hasCmt = !empty($p['comment_list']); ?>
        <div class="inter <?= ($hasLike || $hasCmt) ? '' : 'hide' ?>" data-inter="<?= $pid ?>">
          <div class="likes <?= $hasLike ? '' : 'hide' ?>" data-likes="<?= $pid ?>">
            <span class="ico">♥</span><span class="n"><?= (int)$p['likes'] ?></span> 人觉得很赞
          </div>
          <div class="cmts" data-cmts="<?= $pid ?>">
            <?php foreach ($p['comment_list'] as $c): ?>
              <div class="cmt" data-cid="<?= (int)$c['id'] ?>">
                <b class="who" data-reply="<?= (int)$c['id'] ?>" data-name="<?= e((string)$c['nickname']) ?>"><?= e((string)$c['nickname']) ?></b><?php
                if (!empty($c['reply_to'])): ?> 回复 <b class="who"><?= e((string)$c['reply_to']) ?></b><?php endif; ?>：<span class="c-text"><?= wm_text_html((string)$c['content']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </article>
    <?php endforeach; ?>
  </main>

  <?php
  $pgQuery = $catId > 0 ? 'cat=' . $catId : '';
  echo wm_pager($list['total'], $list['page'], $size, $pgQuery);
  ?>

  <footer class="foot">
    <p>© 2026 <?= e($siteName) ?> · <a href="https://www.770a.cn/" rel="nofollow">龙毅保留所有权利</a> · <?php if ($currentUser !== null): ?><a href="user/index.php">个人中心</a><?php else: ?><a href="user/login.php">用户登录</a><?php endif; ?> ·</p>
  </footer>
</div>

<!-- 评论框 -->
<div class="cmt-bar" id="cmtBar" hidden>
  <form id="cmtForm" autocomplete="off">
    <input type="hidden" name="_token" value="<?= e(wm_csrf_token()) ?>">
    <input type="hidden" name="post_id" value="0">
    <input type="hidden" name="parent_id" value="0">
    <div class="cb-row">
      <input class="cb-nick" type="text" name="nickname" placeholder="昵称" maxlength="20" required>
      <input class="cb-mail" type="email" name="email" placeholder="邮箱（选填）" maxlength="100">
    </div>
    <div class="cb-row">
      <textarea class="cb-text" name="content" placeholder="评论…" maxlength="500" rows="2" required></textarea>
      <button class="cb-send" type="submit">发送</button>
    </div>
    <div class="cb-tip" id="cbTip"><?= $needAudit ? '评论需审核后显示' : '' ?></div>
  </form>
</div>

<!-- 图片查看器 -->
<div class="viewer" id="viewer" hidden>
  <img id="viewerImg" src="" alt="">
  <div class="v-count" id="viewerCount"></div>
  <button class="v-close" id="viewerClose" type="button" aria-label="关闭">×</button>
</div>

<div class="toast" id="toast" hidden></div>

<script>window.WM = <?= ejs(['base' => $base, 'token' => wm_csrf_token(), 'needAudit' => $needAudit]) ?>;</script>
<script src="assets/js/app.js?v=<?= e(WM_VERSION) ?>"></script>
</body>
</html>
