<?php
/**
 * 后台首页：数据概览 + 服务器信息
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
$admin = wm_require_admin();

$s = wm_stats();
$srv = wm_server_info();
$trend = wm_trend(14);

// 目录实际占用（带缓存，避免每次进后台首页都全量递归扫描 uploads）
$usage = wm_upload_usage();
$imgDirBytes = $usage['image'];
$vidDirBytes = $usage['video'];

$recentPosts = wm_all('SELECT id, content, views, likes, comments, status, created_at
                       FROM ' . wm_t('post') . ' ORDER BY id DESC LIMIT 6');
$recentCmts = wm_all('SELECT c.id, c.nickname, c.content, c.status, c.created_at, c.post_id
                      FROM ' . wm_t('comment') . ' c ORDER BY c.id DESC LIMIT 6');

$maxTrend = 1;
foreach ($trend as $t) {
    $maxTrend = max($maxTrend, (int)$t['posts'], (int)$t['comments'], (int)$t['likes']);
}

wm_head('控制台');
?>
<div class="cards">
  <div class="card c1">
    <div class="cd-ico"><i data-lucide="notebook-pen" aria-hidden="true"></i></div>
    <div class="cd-body">
      <div class="cd-num"><?= $s['posts'] ?></div>
      <div class="cd-label">朋友圈总数</div>
      <div class="cd-sub">已发布 <?= $s['posts_online'] ?> · 今日 +<?= $s['posts_today'] ?></div>
    </div>
  </div>
  <div class="card c2">
    <div class="cd-ico"><i data-lucide="heart" aria-hidden="true"></i></div>
    <div class="cd-body">
      <div class="cd-num"><?= $s['likes'] ?></div>
      <div class="cd-label">点赞总数</div>
      <div class="cd-sub">今日 +<?= $s['likes_today'] ?></div>
    </div>
  </div>
  <div class="card c3">
    <div class="cd-ico"><i data-lucide="message-circle" aria-hidden="true"></i></div>
    <div class="cd-body">
      <div class="cd-num"><?= $s['comments'] ?></div>
      <div class="cd-label">评论总数</div>
      <div class="cd-sub">通过 <?= $s['comments_ok'] ?> · 待审 <?= $s['comments_wait'] ?> · 屏蔽 <?= $s['comments_block'] ?></div>
    </div>
  </div>
  <div class="card c4">
    <div class="cd-ico"><i data-lucide="images" aria-hidden="true"></i></div>
    <div class="cd-body">
      <div class="cd-num"><?= e(wm_size((float)$s['media_bytes'])) ?></div>
      <div class="cd-label">媒体占用（数据库统计）</div>
      <div class="cd-sub">图片 <?= $s['images'] ?> 张 <?= e(wm_size((float)$s['image_bytes'])) ?> · 视频 <?= $s['videos'] ?> 个 <?= e(wm_size((float)$s['video_bytes'])) ?></div>
    </div>
  </div>
</div>

<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2>近 14 天趋势</h2><span class="hint">发布 / 评论 / 点赞</span></div>
    <div class="chart">
      <?php foreach ($trend as $t): ?>
        <div class="ch-col" title="<?= e((string)$t['day']) ?>：发布 <?= (int)$t['posts'] ?>，评论 <?= (int)$t['comments'] ?>，点赞 <?= (int)$t['likes'] ?>">
          <div class="ch-bars">
            <i class="b1" style="height:<?= (int)round((int)$t['posts'] / $maxTrend * 100) ?>%"></i>
            <i class="b2" style="height:<?= (int)round((int)$t['comments'] / $maxTrend * 100) ?>%"></i>
            <i class="b3" style="height:<?= (int)round((int)$t['likes'] / $maxTrend * 100) ?>%"></i>
          </div>
          <span class="ch-lb"><?= e((string)$t['label']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="legend"><span><i class="b1"></i>发布</span><span><i class="b2"></i>评论</span><span><i class="b3"></i>点赞</span></div>
  </section>

  <section class="box">
    <div class="box-hd"><h2>存储占用</h2><span class="hint">磁盘实际扫描</span></div>
    <table class="kv">
      <tr><th>图片目录（含缩略图）</th><td><?= e(wm_size((float)$imgDirBytes)) ?></td></tr>
      <tr><th>视频目录</th><td><?= e(wm_size((float)$vidDirBytes)) ?></td></tr>
      <tr><th>上传目录合计</th><td><b><?= e(wm_size((float)($imgDirBytes + $vidDirBytes))) ?></b></td></tr>
      <tr><th>图片 / 视频数量</th><td><?= $s['images'] ?> 张 / <?= $s['videos'] ?> 个</td></tr>
      <tr><th>总浏览量</th><td><?= $s['views'] ?></td></tr>
      <tr><th>分类数量</th><td><?= $s['categories'] ?></td></tr>
    </table>
    <?php if ($srv['disk_total'] > 0): ?>
      <div class="bar-wrap">
        <div class="bar-lb">磁盘使用 <?= $srv['disk_percent'] ?>%（<?= e(wm_size((float)$srv['disk_used'])) ?> / <?= e(wm_size((float)$srv['disk_total'])) ?>）</div>
        <div class="bar"><i style="width:<?= min(100, (int)$srv['disk_percent']) ?>%;background:<?= $srv['disk_percent'] > 88 ? '#fa5151' : '#07c160' ?>"></i></div>
      </div>
    <?php endif; ?>
    <?php if ($srv['mem_total'] > 0): ?>
      <div class="bar-wrap">
        <div class="bar-lb">内存使用 <?= $srv['mem_percent'] ?>%（<?= e(wm_size((float)$srv['mem_used'])) ?> / <?= e(wm_size((float)$srv['mem_total'])) ?>）</div>
        <div class="bar"><i style="width:<?= min(100, (int)$srv['mem_percent']) ?>%;background:<?= $srv['mem_percent'] > 88 ? '#fa5151' : '#1989fa' ?>"></i></div>
      </div>
    <?php endif; ?>
  </section>
</div>

<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2>服务器信息</h2></div>
    <table class="kv">
      <tr><th>网站版本</th><td>V<?= e(WM_VERSION) ?></td></tr>
      <tr><th>操作系统</th><td><?= e((string)$srv['os']) ?></td></tr>
      <tr><th>Web 服务</th><td><?= e((string)$srv['server']) ?></td></tr>
      <tr><th>PHP 版本</th><td><?= e((string)$srv['php_version']) ?></td></tr>
      <tr><th>MySQL 版本</th><td><?= e((string)$srv['mysql_version']) ?></td></tr>
      <tr><th>系统负载</th><td><?= e((string)$srv['load']) ?></td></tr>
      <tr><th>上传限制</th><td><?= e((string)$srv['upload_max']) ?></td></tr>
      <tr><th>内存上限</th><td><?= e((string)$srv['memory_limit']) ?></td></tr>
      <tr><th>服务器时间</th><td><?= e((string)$srv['time']) ?>（<?= e((string)$srv['tz']) ?>）</td></tr>
    </table>
  </section>

  <section class="box">
    <div class="box-hd"><h2>最新动态</h2><a class="hint" href="posts.php">全部 →</a></div>
    <table class="tb">
      <thead><tr><th>内容</th><th>浏览</th><th>赞</th><th>评</th><th>状态</th></tr></thead>
      <tbody>
      <?php if (!$recentPosts): ?>
        <tr><td colspan="5" class="none">暂无数据</td></tr>
      <?php endif; ?>
      <?php foreach ($recentPosts as $p): ?>
        <tr>
          <td><a href="post_edit.php?id=<?= (int)$p['id'] ?>"><?= e(wm_cut((string)$p['content'], 18) ?: '（无文字）') ?></a></td>
          <td><?= (int)$p['views'] ?></td>
          <td><?= (int)$p['likes'] ?></td>
          <td><?= (int)$p['comments'] ?></td>
          <td><?= (int)$p['status'] === 1 ? '<span class="st on">已发布</span>' : '<span class="st off">草稿</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div class="box-hd mt"><h2>最新评论</h2><a class="hint" href="comments.php">全部 →</a></div>
    <table class="tb">
      <thead><tr><th>昵称</th><th>内容</th><th>状态</th></tr></thead>
      <tbody>
      <?php if (!$recentCmts): ?>
        <tr><td colspan="3" class="none">暂无数据</td></tr>
      <?php endif; ?>
      <?php foreach ($recentCmts as $c):
        [$stTxt, $stCls] = wm_comment_status((int)$c['status']); ?>
        <tr>
          <td><?= e(wm_cut((string)$c['nickname'], 8)) ?></td>
          <td><?= e(wm_cut((string)$c['content'], 16)) ?></td>
          <td><span class="st <?= $stCls ?>"><?= $stTxt ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<?php wm_foot();
