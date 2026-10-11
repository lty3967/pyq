<?php
/**
 * TA 的朋友圈：点击首页头像进入，展示该作者发布的全部动态
 *
 * 支持两类作者：
 *   space.php?u=用户ID  —— 前台注册用户发布的动态
 *   space.php?a=管理员ID —— 站长/管理员发布的动态（user_id=0, admin_id>0）
 *
 * 样式对齐微信「个人相册」：封面 + 昵称/签名/头像 + 按日期排列的动态列表，
 * 每条动态左侧为日期标签，右侧为内容摘要与最多 3 张缩略图，点图可全屏查看。
 */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';
require_once WM_INC . '/model.php';

$uid = wm_input_int('u', 'GET', 0);
$aid = wm_input_int('a', 'GET', 0);
$page = max(1, wm_input_int('page', 'GET', 1));
$size = max(5, min(30, (int)wm_setting('page_size', '10')));

$spaceUser = null;
$isAdmin = false;
if ($aid > 0) {
    // 站长/管理员
    $spaceUser = wm_one('SELECT id, username, nickname, avatar, signature FROM ' . wm_t('admin') . ' WHERE id = ? AND status = 1 LIMIT 1', [$aid]);
    $isAdmin = true;
} elseif ($uid > 0) {
    $spaceUser = wm_one('SELECT id, username, nickname, avatar, signature FROM ' . wm_t('user') . ' WHERE id = ? AND status = 1 LIMIT 1', [$uid]);
}
if ($spaceUser === null) {
    wm_redirect('index.php');
}

$list = $isAdmin
    ? wm_post_list($page, $size, 0, false, ['admin_id' => $aid])
    : wm_post_list($page, $size, 0, false, ['user_id' => $uid]);

$siteName = (string)wm_setting('site_name', '我的朋友圈');
$cover = (string)wm_setting('cover_image', '');
$uName = (string)($spaceUser['nickname'] !== '' ? $spaceUser['nickname'] : $spaceUser['username']);
$uAvatar = (string)($spaceUser['avatar'] ?? '');
$uSign = (string)($spaceUser['signature'] ?? '');
$base = wm_base_url();
$spaceQuery = $isAdmin ? 'a=' . $aid : 'u=' . $uid;

/**
 * 个人相册日期标签：与首页 wm_time_ago 的天数口径完全一致
 * （按 time()-发布时间 的整除天数计算，而不是自然日差，避免两边差一天）
 */
function wm_space_date(string $ts, bool $isTop): string
{
    if ($isTop) { return '置顶'; }
    $t = (int)strtotime($ts);
    if ($t <= 0) { return ''; }
    $diff = time() - $t;
    if ($diff < 0) { return date('Y-m-d', $t); }
    if ($diff < 86400) { return '今天'; }
    if ($diff < 172800) { return '昨天'; }
    if ($diff < 2592000) { return floor($diff / 86400) . '天前'; }
    if (date('Y') === date('Y', $t)) { return date('n月j日', $t); }
    return date('Y年n月j日', $t);
}

/** 头像占位（与首页同一套配色规则） */
function wm_space_avatar(string $name, string $avatar): string
{
    if ($avatar !== '' && wm_safe_display_path($avatar) !== '') {
        return '<img class="sp-avatar" src="' . e(wm_file_url($avatar)) . '" alt="" loading="lazy">';
    }
    $ch = mb_substr($name !== '' ? $name : '友', 0, 1);
    $colors = ['#07c160', '#1989fa', '#ff976a', '#7232dd', '#ee0a24', '#00b8d4', '#fa8c16'];
    $idx = abs(crc32($name)) % count($colors);
    return '<span class="sp-avatar ph" style="background:' . $colors[$idx] . '">' . e($ch) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<meta name="theme-color" content="#ededed">
<title><?= e($uName) ?>的朋友圈 - <?= e($siteName) ?></title>
<meta name="robots" content="noindex,follow">
<link rel="stylesheet" href="assets/css/style.css?v=<?= e(WM_ASSET_VER) ?>">
<?php wm_favicon_link(''); ?>
</head>
<body>
<div class="app">

  <header class="sp-cover">
    <?php if ($cover !== '' && wm_safe_display_path($cover) !== ''): ?>
      <img class="sp-cover-img" src="<?= e(wm_file_url($cover)) ?>" alt="">
    <?php else: ?>
      <div class="sp-cover-img cover-default"></div>
    <?php endif; ?>
    <a class="sp-nav" href="index.php" title="返回朋友圈" aria-label="返回朋友圈">‹</a>
  </header>

  <section class="sp-head">
    <div class="sp-id">
      <?= wm_space_avatar($uName, $uAvatar) ?>
      <div class="sp-idinfo">
        <div class="sp-name"><?= e($uName) ?></div>
        <?php if ($uSign !== ''): ?><div class="sp-sign"><?= e(wm_cut($uSign, 60)) ?></div><?php endif; ?>
        <div class="sp-count">朋友圈 · <?= (int)$list['total'] ?> 条</div>
      </div>
    </div>
  </section>

  <main class="sp-list">
    <?php if (!$list['rows']): ?>
      <div class="sp-empty">
        <div class="sp-empty-ico">📭</div>
        <p>Ta 还没有发布过内容</p>
      </div>
    <?php endif; ?>

    <?php foreach ($list['rows'] as $p):
      $pid = (int)$p['id'];
      $isTop = (int)$p['is_top'] === 1;
      $media = $p['media'];
      $imgs = array_values(array_filter($media, static fn($m) => $m['type'] === 'image'));
      $vids = array_values(array_filter($media, static fn($m) => $m['type'] === 'video'));
      $content = trim((string)$p['content']);
      $music = $p['music'] ?? null;
    ?>
    <article class="sp-item" data-id="<?= $pid ?>">
      <div class="sp-date"><?= e(wm_space_date((string)$p['created_at'], $isTop)) ?></div>
      <div class="sp-main">
        <?php if ($content !== ''): ?>
          <div class="sp-text"><?= wm_cut($content, 120) ?></div>
        <?php elseif ($music !== null && $music !== []): ?>
          <div class="sp-text">分享了音乐</div>
        <?php elseif ($vids): ?>
          <div class="sp-text">发布了视频</div>
        <?php endif; ?>
        <?= wm_music_card_html($music) ?>
        <div class="sp-stats">♥ <b><?= (int)$p['likes'] ?></b> · 评论 <b><?= (int)$p['comments'] ?></b> · 浏览 <?= (int)$p['views'] ?></div>
      </div>
      <?php if ($imgs): ?>
        <div class="grid sp-thumbs">
          <?php foreach (array_slice($imgs, 0, 3) as $m):
            $src = $m['thumb'] !== '' ? (string)$m['thumb'] : (string)$m['path']; ?>
            <div class="cell">
              <img src="<?= e(wm_file_url($src)) ?>" data-full="<?= e(wm_file_url((string)$m['path'])) ?>" alt="" loading="lazy">
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ($vids):
        $v = $vids[0];
        $vThumb = (string)($v['thumb'] ?? ''); ?>
        <div class="grid sp-thumbs">
          <div class="cell sp-video-cell">
            <?php if ($vThumb !== '' && wm_safe_display_path($vThumb) !== ''): ?>
              <img src="<?= e(wm_file_url($vThumb)) ?>" alt="" loading="lazy">
            <?php endif; ?>
            <span class="sp-play" aria-hidden="true">▶</span>
          </div>
        </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </main>

  <?php echo wm_pager($list['total'], $list['page'], $size, $spaceQuery); ?>

  <footer class="foot">
    <p>© <?= date('Y') ?> <?= e($siteName) ?></p>
  </footer>
</div>

<!-- 音乐播放条（分享音乐卡片的播放按钮可用） -->
<div class="music-player" id="musicPlayer" hidden>
  <div class="mp-inner">
    <span class="mp-disc" id="mpDisc"><img id="mpCover" src="" alt=""></span>
    <div class="mp-text">
      <div class="mp-name" id="mpName"></div>
      <div class="mp-artist" id="mpArtist"></div>
    </div>
    <button class="mp-btn" id="mpToggle" type="button" aria-label="播放 / 暂停"><span id="mpIco">▶</span></button>
    <button class="mp-close" id="mpClose" type="button" aria-label="关闭播放器">×</button>
  </div>
  <div class="mp-progress"><span id="mpBar"></span></div>
  <audio id="mpAudio" preload="none"></audio>
</div>

<!-- 图片查看器（复用前台样式与交互） -->
<div class="viewer" id="viewer" hidden>
  <img id="viewerImg" src="" alt="">
  <div class="v-count" id="viewerCount"></div>
  <button class="v-close" id="viewerClose" type="button" aria-label="关闭">×</button>
</div>

<div class="toast" id="toast" hidden></div>

<script>window.WM = <?= ejs(['base' => $base]) ?>;</script>
<script src="assets/js/app.js?v=<?= e(WM_ASSET_VER) ?>"></script>
</body>
</html>
