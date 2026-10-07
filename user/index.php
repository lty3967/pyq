<?php
/**
 * 用户中心 - 我的动态
 *
 * 统计（动态数 / 获赞 / 收评 / 总浏览）与最近动态列表。
 * 列表调用 wm_post_list(..., adminView = true)：
 * 表示「按作者视角查看」，因而不过滤 status，草稿也会列出来。
 */
declare(strict_types=1);

require __DIR__ . '/layout.php';

$userId = (int) $user['id'];

// ---------- 删除自己的动态 ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();
    $action = wm_input('act');
    $postId = wm_input_int('id');

    if ($action === 'delete' && $postId > 0) {
        // 必须先按 user_id 校验归属，否则可传别人的 post_id 删掉他人动态
        $own = wm_one('SELECT id, content FROM ' . wm_t('post') . ' WHERE id = ? AND user_id = ? LIMIT 1',
            [$postId, $userId]);
        if ($own === null) {
            wm_flash(false, '动态不存在或无权删除');
        } else {
            $res = wm_post_delete([$postId]);
            wm_flash((bool) $res['ok'], $res['msg']);
        }
    } else {
        wm_flash(false, '未知操作');
    }
    wm_redirect('index.php');
}

$stats = [
    'posts' => (int) wm_value(
        'SELECT COUNT(*) FROM ' . wm_t('post') . ' WHERE user_id = ?',
        [$userId]
    ),
    'likes' => (int) wm_value(
        'SELECT COUNT(*) FROM ' . wm_t('like') . ' l
         INNER JOIN ' . wm_t('post') . ' p ON p.id = l.post_id
         WHERE p.user_id = ?',
        [$userId]
    ),
    'comments' => (int) wm_value(
        'SELECT COUNT(*) FROM ' . wm_t('comment') . ' c
         INNER JOIN ' . wm_t('post') . ' p ON p.id = c.post_id
         WHERE p.user_id = ?',
        [$userId]
    ),
    'views' => (int) wm_value(
        'SELECT COALESCE(SUM(views), 0) FROM ' . wm_t('post') . ' WHERE user_id = ?',
        [$userId]
    ),
];

$page = max(1, wm_input_int('page', 'GET', 1));
$size = 10;
$list = wm_post_list($page, $size, 0, true, ['user_id' => $userId]);

user_head('我的动态');
user_flash();
?>
<div class="stat-grid">
    <div><strong><?= $stats['posts'] ?></strong><span>我的动态</span></div>
    <div><strong><?= $stats['likes'] ?></strong><span>获得点赞</span></div>
    <div><strong><?= $stats['comments'] ?></strong><span>收到评论</span></div>
    <div><strong><?= $stats['views'] ?></strong><span>总浏览量</span></div>
</div>

<section class="panel">
    <div class="panel-title">
        <span>最近发布</span>
        <a class="primary small" href="post.php">发布动态</a>
    </div>

    <table class="tb">
        <thead>
        <tr>
            <th>内容</th>
            <th style="width:78px">媒体</th>
            <th style="width:56px">浏览</th>
            <th style="width:48px">赞</th>
            <th style="width:48px">评论</th>
            <th style="width:100px">状态</th>
            <th style="width:100px">时间</th>
            <th style="width:150px">操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$list['rows']): ?>
            <tr><td colspan="8" class="none">暂无动态</td></tr>
        <?php endif; ?>

        <?php foreach ($list['rows'] as $post):
            $pid = (int) $post['id'];
            $ms = $post['media'] ?? [];
            $music = $post['music'] ?? null;
            // 纯音乐动态没有文字，内容列只显示音乐标签，不显示占位文案
            $summary = wm_cut((string) $post['content'], 30);
            if ($summary === '' && !$ms) {
                $summary = '（无文字内容）';
            } ?>
            <tr>
                <td>
                    <?php if ($summary !== ''): ?>
                        <a href="post.php?id=<?= $pid ?>"><?= e($summary) ?></a>
                    <?php endif; ?>
                    <?php if (!empty($music)): ?>
                        <span class="st on" title="<?= e((string)$music['song_name'] . ((string)$music['artist'] !== '' ? ' - ' . $music['artist'] : '')) ?>">♪ <?= e(wm_cut((string)$music['song_name'], 12)) ?></span>
                    <?php endif; ?>
                    <?php if ((int) $post['allow_comment'] !== 1): ?><span class="st off">已关评论</span><?php endif; ?>
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
                        <?php if (!$ms && empty($music)): ?><span class="hint">—</span><?php endif; ?>
                    </div>
                    <?php if (!empty($music)): ?>
                        <div class="thumbs" style="margin-top:6px" title="<?= e((string)$music['song_name']) ?>">
                            <?php if ((string) $music['cover'] !== ''): ?>
                                <img src="<?= e((string) $music['cover']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                            <?php else: ?>
                                <span class="vtag">音乐</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td><?= (int) $post['views'] ?></td>
                <td><?= (int) $post['likes'] ?></td>
                <td><?= (int) $post['comments'] ?></td>
                <td class="status-cell">
                    <span class="st <?= (int) $post['status'] === 1 ? 'on' : 'wait' ?>"><?= (int) $post['status'] === 1 ? '已发布' : '草稿' ?></span>
                </td>
                <td><?= e(date('m-d H:i', strtotime((string) $post['created_at']))) ?></td>
                <td class="row-acts">
                    <a class="btn sm ghost" href="post.php?id=<?= $pid ?>">编辑</a>
                    <form method="post" action="index.php" class="inline-form"
                          data-confirm="删除后该动态及其媒体、评论、点赞都会一并移除，且不可恢复，确定删除？">
                        <?= wm_csrf_field() ?>
                        <input type="hidden" name="act" value="delete">
                        <input type="hidden" name="id" value="<?= $pid ?>">
                        <button class="btn sm danger" type="submit">删除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?= wm_pager((int) $list['total'], (int) $list['page'], $size) ?>
</section>
<?php user_foot();
