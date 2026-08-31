<?php
declare(strict_types=1);

require __DIR__ . '/layout.php';

$userId = (int) $user['id'];
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

$list = wm_post_list(1, 10, 0, true, ['user_id' => $userId]);

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

    <table>
        <thead>
        <tr>
            <th>内容</th>
            <th>浏览</th>
            <th>点赞</th>
            <th>评论</th>
            <th>状态</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$list['rows']): ?>
            <tr><td colspan="6" class="muted">暂无动态</td></tr>
        <?php endif; ?>

        <?php foreach ($list['rows'] as $post): ?>
            <tr>
                <td><?= e(wm_cut((string) $post['content'], 35) ?: '（图片/视频）') ?></td>
                <td><?= (int) $post['views'] ?></td>
                <td><?= (int) $post['likes'] ?></td>
                <td><?= (int) $post['comments'] ?></td>
                <td><?= (int) $post['status'] === 1 ? '已发布' : '草稿' ?></td>
                <td><a href="post.php?id=<?= (int) $post['id'] ?>">编辑</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php user_foot();
