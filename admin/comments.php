<?php
/**
 * 评论管理：审核 / 屏蔽 / 删除 / 回复
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
$admin = wm_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act');

    if ($act === 'batch') {
        $bAct = wm_input('batch_act');
        $ids = $_POST['ids'] ?? [];
        $ids = is_array($ids) ? array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)) : [];
        $ids = array_slice($ids, 0, 300);

        if (!$ids) {
            wm_flash(false, '未选择任何评论');
        } else {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $posts = array_map('intval', array_column(
                wm_all('SELECT DISTINCT post_id FROM ' . wm_t('comment') . ' WHERE id IN (' . $in . ')', $ids), 'post_id'));

            $map = ['pass' => 1, 'block' => 2, 'wait' => 0];
            if (isset($map[$bAct])) {
                wm_exec('UPDATE ' . wm_t('comment') . ' SET status = ' . (int)$map[$bAct] . ' WHERE id IN (' . $in . ')', $ids);
                wm_log('评论审核', $bAct . '：' . count($ids) . ' 条');
                wm_flash(true, '已处理 ' . count($ids) . ' 条评论');
            } elseif ($bAct === 'delete') {
                // 同时清理其子回复
                wm_exec('DELETE FROM ' . wm_t('comment') . ' WHERE parent_id IN (' . $in . ')', $ids);
                $n = wm_exec('DELETE FROM ' . wm_t('comment') . ' WHERE id IN (' . $in . ')', $ids);
                wm_log('删除评论', 'ID：' . implode(',', $ids));
                wm_flash(true, '已删除 ' . $n . ' 条评论');
            } elseif ($bAct === 'block_ip') {
                $ips = array_column(wm_all('SELECT DISTINCT ip FROM ' . wm_t('comment') . ' WHERE id IN (' . $in . ')', $ids), 'ip');
                $cur = array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)wm_setting('block_ips', '')) ?: []));
                foreach ($ips as $ip) {
                    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $cur, true)) { $cur[] = $ip; }
                }
                wm_setting_set('block_ips', implode("\n", array_slice($cur, 0, 500)));
                wm_exec('UPDATE ' . wm_t('comment') . ' SET status = 2 WHERE id IN (' . $in . ')', $ids);
                wm_log('屏蔽评论 IP', implode(',', $ips));
                wm_flash(true, '已屏蔽相关 IP 并隐藏这些评论');
            } else {
                wm_flash(false, '未知操作');
            }
            foreach ($posts as $pid) { wm_post_resync($pid); }
        }
        wm_redirect('comments.php');
    }

    if ($act === 'moderate') {
        $commentId = wm_input_int('id');
        $status = wm_input_int('status');
        $comment = wm_one('SELECT id, post_id FROM ' . wm_t('comment') . ' WHERE id = ? LIMIT 1', [$commentId]);
        if ($comment === null || !in_array($status, [0, 1, 2], true)) {
            wm_flash(false, '评论不存在或审核状态无效');
        } else {
            wm_exec('UPDATE ' . wm_t('comment') . ' SET status = ? WHERE id = ?', [$status, $commentId]);
            wm_post_resync((int) $comment['post_id']);
            wm_log('审核评论', '评论 ID：' . $commentId . '，状态：' . $status);
            wm_flash(true, $status === 1 ? '评论已通过' : ($status === 2 ? '评论已屏蔽' : '评论已设为待审'));
        }
        wm_redirect('comments.php');
    }

    if ($act === 'reply') {
        $postId = wm_input_int('post_id');
        $parentId = wm_input_int('parent_id');
        $content = wm_input('content');
        if ($postId <= 0 || $content === '' || mb_strlen($content) > 500) {
            wm_flash(false, '回复内容需为 1-500 字');
        } else {
            $exists = wm_value('SELECT id FROM ' . wm_t('post') . ' WHERE id = ? LIMIT 1', [$postId]);
            if ($exists === null) {
                wm_flash(false, '动态不存在');
            } else {
                if ($parentId > 0) {
                    $ok = wm_value('SELECT id FROM ' . wm_t('comment') . ' WHERE id = ? AND post_id = ? LIMIT 1', [$parentId, $postId]);
                    if ($ok === null) { $parentId = 0; }
                }
                $nick = (string)($admin['nickname'] !== '' ? $admin['nickname'] : $admin['username']);
                wm_exec('INSERT INTO ' . wm_t('comment') . ' (post_id, parent_id, nickname, email, content, status, ip, ip_hash, ua, created_at)
                         VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())',
                    [$postId, $parentId, $nick, (string)$admin['email'], $content, wm_client_ip(), wm_ip_hash(), 'admin']);
                wm_post_resync($postId);
                wm_log('回复评论', '动态 ID：' . $postId);
                wm_flash(true, '回复已发布');
            }
        }
        wm_redirect('comments.php' . ($postId > 0 ? '?post_id=' . $postId : ''));
    }
}

// ---------- 列表 ----------
$page = max(1, wm_input_int('page', 'GET', 1));
$size = 20;
$statusF = wm_input('status', 'GET');
$statusF = in_array($statusF, ['0', '1', '2'], true) ? $statusF : '';
$postF = wm_input_int('post_id', 'GET', 0);
$kw = mb_substr(wm_input('kw', 'GET'), 0, 50);
$badOnly = wm_input('bad', 'GET') === '1';
$replyId = wm_input_int('reply_id', 'GET', 0);
$replyTarget = $replyId > 0 ? wm_one(
    'SELECT id, post_id, nickname FROM ' . wm_t('comment') . ' WHERE id = ? LIMIT 1',
    [$replyId]
) : null;

$where = [];
$params = [];
if ($statusF !== '') { $where[] = 'c.status = :st'; $params[':st'] = (int)$statusF; }
if ($postF > 0) { $where[] = 'c.post_id = :pid'; $params[':pid'] = $postF; }
if ($kw !== '') {
    $where[] = '(c.content LIKE :kw OR c.nickname LIKE :kw OR c.ip = :ipx)';
    $params[':kw'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $kw) . '%';
    $params[':ipx'] = $kw;
}
if ($badOnly) { $where[] = "c.bad_hit <> ''"; }
$sqlW = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$total = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('comment') . ' c' . $sqlW, $params);
$offset = ($page - 1) * $size;
$rows = wm_all('SELECT c.*, p.content AS post_content FROM ' . wm_t('comment') . ' c
                LEFT JOIN ' . wm_t('post') . ' p ON p.id = c.post_id
                ' . $sqlW . ' ORDER BY c.id DESC LIMIT ' . $size . ' OFFSET ' . $offset, $params);

$cnt = [
    'all'   => (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('comment')),
    'wait'  => (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('comment') . ' WHERE status = 0'),
    'pass'  => (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('comment') . ' WHERE status = 1'),
    'block' => (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('comment') . ' WHERE status = 2'),
    'bad'   => (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('comment') . " WHERE bad_hit <> ''"),
];

$qs = [];
if ($statusF !== '') { $qs[] = 'status=' . $statusF; }
if ($postF > 0) { $qs[] = 'post_id=' . $postF; }
if ($kw !== '') { $qs[] = 'kw=' . urlencode($kw); }
if ($badOnly) { $qs[] = 'bad=1'; }
$baseQ = implode('&', $qs);

wm_head('评论管理');
?>
<form class="toolbar" method="get" action="comments.php">
  <div class="tabs">
    <a class="tab <?= $statusF === '' && !$badOnly ? 'on' : '' ?>" href="comments.php">全部 <?= $cnt['all'] ?></a>
    <a class="tab <?= $statusF === '0' ? 'on' : '' ?>" href="comments.php?status=0">待审核 <?= $cnt['wait'] ?></a>
    <a class="tab <?= $statusF === '1' ? 'on' : '' ?>" href="comments.php?status=1">已通过 <?= $cnt['pass'] ?></a>
    <a class="tab <?= $statusF === '2' ? 'on' : '' ?>" href="comments.php?status=2">已屏蔽 <?= $cnt['block'] ?></a>
    <a class="tab <?= $badOnly ? 'on' : '' ?>" href="comments.php?bad=1">含违禁词 <?= $cnt['bad'] ?></a>
  </div>
  <input class="inp" type="text" name="kw" value="<?= e($kw) ?>" placeholder="搜索内容 / 昵称 / IP" maxlength="50">
  <?php if ($postF > 0): ?><input type="hidden" name="post_id" value="<?= $postF ?>"><?php endif; ?>
  <button class="btn sm" type="submit">搜索</button>
  <span class="sp"></span>
  <a class="btn sm ghost" href="badwords.php">违禁词设置</a>
</form>

<?php if ($postF > 0): ?>
  <div class="alert warn">当前仅显示动态 #<?= $postF ?> 的评论。<a href="comments.php">查看全部</a></div>
<?php endif; ?>

<div class="box">
    <table class="tb">
      <thead>
        <tr>
          <th style="width:34px"><input type="checkbox" id="checkAll" form="batchForm"></th>
          <th style="width:100px">昵称</th>
          <th>评论内容</th>
          <th style="width:110px">所属动态</th>
          <th style="width:118px">来源</th>
          <th style="width:66px">状态</th>
          <th style="width:112px">时间</th>
          <th style="width:180px">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="8" class="none">暂无评论</td></tr><?php endif; ?>
      <?php foreach ($rows as $c):
        $stMap = [0 => ['待审', 'wait'], 1 => ['通过', 'on'], 2 => ['屏蔽', 'off']];
        [$stTxt, $stCls] = $stMap[(int)$c['status']] ?? ['未知', 'off']; ?>
        <tr>
          <td><input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>" form="batchForm"></td>
          <td>
            <?= e((string)$c['nickname']) ?>
            <?php if ((string)$c['email'] !== ''): ?><div class="hint"><?= e(wm_cut((string)$c['email'], 16)) ?></div><?php endif; ?>
          </td>
          <td>
            <div class="cmt-text"><?= e((string)$c['content']) ?></div>
            <?php if ((string)$c['bad_hit'] !== ''): ?><span class="bad-tag">违禁词：<?= e(wm_cut((string)$c['bad_hit'], 20)) ?></span><?php endif; ?>
            <?php if ((int)$c['parent_id'] > 0): ?><span class="hint">（回复 #<?= (int)$c['parent_id'] ?>）</span><?php endif; ?>
          </td>
          <td>
            <?php if ($c['post_content'] !== null): ?>
              <a href="post_edit.php?id=<?= (int)$c['post_id'] ?>"><?= e(wm_cut((string)$c['post_content'], 10) ?: '#' . (int)$c['post_id']) ?></a>
            <?php else: ?>
              <span class="hint">动态已删除</span>
            <?php endif; ?>
          </td>
          <td><span class="hint"><?= e((string)$c['ip']) ?></span></td>
          <td><span class="st <?= $stCls ?>"><?= $stTxt ?></span></td>
          <td><span class="hint"><?= e(date('m-d H:i', strtotime((string)$c['created_at']))) ?></span></td>
          <td class="comment-actions">
            <?php if ((int) $c['status'] === 0): ?>
              <details class="review-menu">
                <summary class="btn sm ghost">审核</summary>
                <div class="review-options">
                  <form method="post">
                    <?= wm_csrf_field() ?>
                    <input type="hidden" name="act" value="moderate">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <input type="hidden" name="status" value="1">
                    <button type="submit">通过</button>
                  </form>
                  <form method="post">
                    <?= wm_csrf_field() ?>
                    <input type="hidden" name="act" value="moderate">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <input type="hidden" name="status" value="2">
                    <button class="reject" type="submit">不通过</button>
                  </form>
                </div>
              </details>
            <?php else: ?>
              <form method="post" style="display:inline">
                <?= wm_csrf_field() ?>
                <input type="hidden" name="act" value="moderate">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="status" value="<?= (int) $c['status'] === 1 ? 2 : 1 ?>">
                <button class="btn sm ghost" type="submit"><?= (int) $c['status'] === 1 ? '屏蔽' : '恢复' ?></button>
              </form>
            <?php endif; ?>
            <a class="btn sm ghost" href="comments.php?reply_id=<?= (int) $c['id'] ?>">回复</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($rows): ?>
      <form id="batchForm" method="post" action="comments.php" data-batch="1" class="toolbar" style="margin:14px 0 0;box-shadow:none;padding:12px 0 0;border-top:1px solid #f0f0f0">
        <?= wm_csrf_field() ?>
        <input type="hidden" name="act" value="batch">
        <select class="inp" name="batch_act" required>
          <option value="">批量操作…</option>
          <option value="pass">通过审核</option>
          <option value="block">屏蔽（前台不显示）</option>
          <option value="wait">标记为待审</option>
          <option value="delete">删除</option>
          <option value="block_ip">屏蔽该 IP 后续评论</option>
        </select>
        <button class="btn sm danger" type="submit" data-confirm="确认执行所选批量操作？">执行</button>
        <span class="sp"></span>
        <span class="hint">共 <?= $total ?> 条</span>
      </form>
    <?php endif; ?>
  </div>

<?= wm_pager($total, $page, $size, $baseQ) ?>

<?php if ($replyTarget !== null): ?>
  <div class="box reply-box" style="margin-top:14px">
    <div class="box-hd">
      <h2>回复评论</h2>
      <a class="hint" href="comments.php">取消回复</a>
    </div>
    <div class="reply-context">
      正在回复：<strong><?= e((string) $replyTarget['nickname']) ?></strong>
      <span>评论 ID：<?= (int) $replyTarget['id'] ?>，动态 ID：<?= (int) $replyTarget['post_id'] ?></span>
    </div>
    <form class="form" method="post" action="comments.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="reply">
      <input type="hidden" name="post_id" value="<?= (int) $replyTarget['post_id'] ?>">
      <input type="hidden" name="parent_id" value="<?= (int) $replyTarget['id'] ?>">
      <div class="fr">
        <label for="rc">回复内容</label>
        <div class="fc">
          <textarea class="inp" id="rc" name="content" rows="3" maxlength="500" required placeholder="输入回复内容"></textarea>
        </div>
      </div>
      <div class="fr">
        <label></label>
        <div class="fc acts"><button class="btn" type="submit">发布回复</button></div>
      </div>
    </form>
  </div>
<?php endif; ?>
<?php wm_foot();
