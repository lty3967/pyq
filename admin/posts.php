<?php
/**
 * 内容管理
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
$admin = wm_require_admin();

// ---------- 操作处理 ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act');

    if ($act === 'batch') {
        $bAct = wm_input('batch_act');
        $ids = $_POST['ids'] ?? [];
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)) : [];
        $ids = array_slice($ids, 0, 200);

        if (!$ids) {
            wm_flash(false, '未选择任何内容');
        } else {
            $in = implode(',', array_fill(0, count($ids), '?'));
            switch ($bAct) {
                case 'publish':
                    wm_exec('UPDATE ' . wm_t('post') . ' SET status = 1 WHERE id IN (' . $in . ')', $ids);
                    wm_log('批量发布', '共 ' . count($ids) . ' 条');
                    wm_flash(true, '已发布 ' . count($ids) . ' 条');
                    break;
                case 'draft':
                    wm_exec('UPDATE ' . wm_t('post') . ' SET status = 0 WHERE id IN (' . $in . ')', $ids);
                    wm_log('批量下架', '共 ' . count($ids) . ' 条');
                    wm_flash(true, '已转为草稿 ' . count($ids) . ' 条');
                    break;
                case 'top':
                    wm_exec('UPDATE ' . wm_t('post') . ' SET is_top = 1 WHERE id IN (' . $in . ')', $ids);
                    wm_log('批量置顶', '共 ' . count($ids) . ' 条');
                    wm_flash(true, '已置顶');
                    break;
                case 'untop':
                    wm_exec('UPDATE ' . wm_t('post') . ' SET is_top = 0 WHERE id IN (' . $in . ')', $ids);
                    wm_log('批量取消置顶', '共 ' . count($ids) . ' 条');
                    wm_flash(true, '已取消置顶');
                    break;
                case 'delete':
                    $pdo = wm_db();
                    $n = 0;
                    try {
                        $pdo->beginTransaction();
                        $mediaRows = wm_all('SELECT path, thumb FROM ' . wm_t('media') . ' WHERE post_id IN (' . $in . ')', $ids);
                        wm_exec('DELETE FROM ' . wm_t('media') . ' WHERE post_id IN (' . $in . ')', $ids);
                        wm_exec('DELETE FROM ' . wm_t('comment') . ' WHERE post_id IN (' . $in . ')', $ids);
                        wm_exec('DELETE FROM ' . wm_t('like') . ' WHERE post_id IN (' . $in . ')', $ids);
                        wm_exec('DELETE FROM ' . wm_t('view') . ' WHERE post_id IN (' . $in . ')', $ids);
                        $n = wm_exec('DELETE FROM ' . wm_t('post') . ' WHERE id IN (' . $in . ')', $ids);
                        $pdo->commit();
                        foreach ($mediaRows as $m) {
                            wm_media_unlink((string)$m['path']);
                            if ((string)$m['thumb'] !== '') { wm_media_unlink((string)$m['thumb']); }
                        }
                        wm_log('删除动态', 'ID：' . implode(',', $ids));
                        // 媒体文件已删除，让后台的磁盘占用统计立即重算
                        wm_upload_usage_reset();
                        wm_flash(true, '已删除 ' . $n . ' 条内容及其媒体、评论、点赞');
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) { $pdo->rollBack(); }
                        error_log('post delete fail: ' . $e->getMessage());
                        wm_flash(false, '删除失败');
                    }
                    break;
                default:
                    wm_flash(false, '未知操作');
            }
        }
        // 保留原筛选条件原样回跳；原实现用白名单过滤 query，
        // 会把中文与 URL 编码（%xx）一起吃掉导致筛选丢失，这里只排除控制字符
        $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
        wm_redirect('posts.php' . (($qs !== '' && !preg_match('/[\x00-\x1F\x7F]/', $qs)) ? '?' . $qs : ''));
    }
}

// ---------- 列表 ----------
$page = max(1, wm_input_int('page', 'GET', 1));
$size = 15;
$statusF = wm_input('status', 'GET');
$statusF = in_array($statusF, ['0', '1'], true) ? $statusF : '';
$catF = wm_input_int('cat', 'GET', 0);
$userF = wm_input_int('user_id', 'GET', 0);
$kw = mb_substr(wm_input('kw', 'GET'), 0, 50);

$list = wm_post_list($page, $size, $catF, true, ['status' => $statusF, 'keyword' => $kw] + ($userF > 0 ? ['user_id' => $userF] : []));
$cats = wm_categories(false);
// wm_post_list() 已把媒体挂到每行的 media 键上，这里无需再查一次

$qs = [];
if ($statusF !== '') { $qs[] = 'status=' . $statusF; }
if ($catF > 0) { $qs[] = 'cat=' . $catF; }
if ($userF > 0) { $qs[] = 'user_id=' . $userF; }
if ($kw !== '') { $qs[] = 'kw=' . urlencode($kw); }
$baseQ = implode('&', $qs);

wm_head('内容管理');
?>
<form class="toolbar" method="get" action="posts.php">
  <div class="tabs">
    <a class="tab <?= $statusF === '' ? 'on' : '' ?>" href="posts.php">全部</a>
    <a class="tab <?= $statusF === '1' ? 'on' : '' ?>" href="posts.php?status=1">已发布</a>
    <a class="tab <?= $statusF === '0' ? 'on' : '' ?>" href="posts.php?status=0">草稿</a>
  </div>
  <select class="inp" name="cat">
    <option value="0">全部分类</option>
    <?php foreach ($cats as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $catF === (int)$c['id'] ? 'selected' : '' ?>><?= e((string)$c['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <input class="inp" type="text" name="kw" value="<?= e($kw) ?>" placeholder="搜索内容" maxlength="50">
  <?php if ($statusF !== ''): ?><input type="hidden" name="status" value="<?= e($statusF) ?>"><?php endif; ?>
  <button class="btn sm" type="submit">筛选</button>
  <span class="sp"></span>
  <a class="btn sm" href="post_edit.php">＋ 发布新内容</a>
</form>

<form method="post" action="posts.php<?= $baseQ !== '' ? '?' . e($baseQ) : '' ?>" data-batch="1">
  <?= wm_csrf_field() ?>
  <input type="hidden" name="act" value="batch">

  <div class="box">
    <table class="tb">
      <thead>
        <tr>
          <th style="width:34px"><input type="checkbox" id="checkAll"></th>
          <th>内容</th>
          <th style="width:110px">发布者</th>
          <th style="width:78px">媒体</th>
          <th style="width:64px">分类</th>
          <th style="width:56px">浏览</th>
          <th style="width:48px">赞</th>
          <th style="width:48px">评论</th>
          <th style="width:70px">状态</th>
          <th style="width:120px">时间</th>
          <th style="width:96px">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$list['rows']): ?>
        <tr><td colspan="11" class="none">暂无内容</td></tr>
      <?php endif; ?>
      <?php foreach ($list['rows'] as $p):
        $pid = (int)$p['id'];
        $ms = $p['media'] ?? []; ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= $pid ?>"></td>
          <td>
            <a href="post_edit.php?id=<?= $pid ?>"><?= e(wm_cut((string)$p['content'], 30) ?: '（无文字内容）') ?></a>
            <?php if ((int)$p['is_top'] === 1): ?><span class="st wait">置顶</span><?php endif; ?>
            <?php if ((int)$p['allow_comment'] !== 1): ?><span class="st off">已关评论</span><?php endif; ?>
          </td>
          <td>
            <?php if ((int) $p['user_id'] > 0): ?>
              <span class="st on">用户</span>
              <?= e((string) ($p['author'] ?: '未知用户')) ?>
            <?php else: ?>
              <span class="st wait">管理员</span>
              <?= e((string) ($p['author'] ?: '站长')) ?>
            <?php endif; ?>
          </td>
          <td>
            <div class="thumbs">
              <?php foreach (array_slice($ms, 0, 3) as $m): ?>
                <?php if ($m['type'] === 'video'): ?>
                  <span class="vtag">视频</span>
                <?php else: ?>
                  <img src="../<?= e((string)($m['thumb'] !== '' ? $m['thumb'] : $m['path'])) ?>" alt="" loading="lazy">
                <?php endif; ?>
              <?php endforeach; ?>
              <?php if (count($ms) > 3): ?><span class="hint">+<?= count($ms) - 3 ?></span><?php endif; ?>
              <?php if (!$ms): ?><span class="hint">—</span><?php endif; ?>
            </div>
          </td>
          <td><?= $p['cat_name'] !== null ? e((string)$p['cat_name']) : '<span class="hint">未分类</span>' ?></td>
          <td><?= (int)$p['views'] ?></td>
          <td><?= (int)$p['likes'] ?></td>
          <td><a href="comments.php?post_id=<?= $pid ?>"><?= (int)$p['comments'] ?></a></td>
          <td class="status-cell"><?= (int)$p['status'] === 1 ? '<span class="st on">已发布</span>' : '<span class="st off">草稿</span>' ?></td>
          <td><span class="hint"><?= e(date('Y-m-d H:i', strtotime((string)$p['created_at']))) ?></span></td>
          <td class="acts"><a class="btn sm ghost" href="post_edit.php?id=<?= $pid ?>">编辑</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($list['rows']): ?>
      <div class="toolbar" style="margin:14px 0 0;box-shadow:none;padding:12px 0 0;border-top:1px solid #f0f0f0">
        <select class="inp" name="batch_act" required>
          <option value="">批量操作…</option>
          <option value="publish">发布</option>
          <option value="draft">转为草稿</option>
          <option value="top">置顶</option>
          <option value="untop">取消置顶</option>
          <option value="delete">删除（含媒体、评论、点赞）</option>
        </select>
        <button class="btn sm danger" type="submit" data-confirm="确认执行所选批量操作？删除操作不可恢复。">执行</button>
        <span class="sp"></span>
        <span class="hint">共 <?= (int)$list['total'] ?> 条</span>
      </div>
    <?php endif; ?>
  </div>
</form>

<?= wm_pager((int)$list['total'], (int)$list['page'], $size, $baseQ) ?>
<?php wm_foot();
