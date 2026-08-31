<?php
declare(strict_types=1);

require __DIR__ . '/layout.php';

$userId = (int) $user['id'];
$replyId = wm_input_int('reply_id', 'GET', 0);
$replyTarget = $replyId > 0 ? wm_one(
    'SELECT c.id, c.post_id, c.nickname
     FROM ' . wm_t('comment') . ' c
     INNER JOIN ' . wm_t('post') . ' p ON p.id = c.post_id
     WHERE c.id = ? AND p.user_id = ? AND c.user_id <> ?
     LIMIT 1',
    [$replyId, $userId, $userId]
) : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();

    $action = wm_input('act');
    $commentId = wm_input_int('id');
    $comment = wm_one(
        'SELECT c.id, c.post_id, c.nickname
         FROM ' . wm_t('comment') . ' c
         INNER JOIN ' . wm_t('post') . ' p ON p.id = c.post_id
         WHERE c.id = ? AND p.user_id = ? AND c.user_id <> ?
         LIMIT 1',
        [$commentId, $userId, $userId]
    );

    if ($comment === null) {
        wm_flash(false, '评论不存在或无权操作');
        wm_redirect('comments.php');
    }

    if ($action === 'moderate') {
        $status = wm_input_int('status');
        if (!in_array($status, [1, 2], true)) {
            wm_flash(false, '审核状态无效');
        } else {
            wm_exec(
                'UPDATE ' . wm_t('comment') . ' SET status = ? WHERE id = ?',
                [$status, $commentId]
            );
            wm_post_resync((int) $comment['post_id']);
            wm_flash(true, $status === 1 ? '评论已通过' : '评论已不通过');
        }
        wm_redirect('comments.php');
    }

    if ($action === 'reply') {
        $content = wm_input('content');
        if ($content === '' || mb_strlen($content) > 500) {
            wm_flash(false, '回复内容需为 1-500 字');
        } else {
            $nickname = (string) ($user['nickname'] ?: $user['username']);
            wm_exec(
                'INSERT INTO ' . wm_t('comment') . ' (post_id, user_id, parent_id, nickname, email, content, status, ip, ip_hash, ua, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, NOW())',
                [
                    (int) $comment['post_id'],
                    $userId,
                    $commentId,
                    $nickname,
                    (string) $user['email'],
                    $content,
                    wm_client_ip(),
                    wm_ip_hash(),
                    'user',
                ]
            );
            wm_post_resync((int) $comment['post_id']);
            wm_flash(true, '回复已发布');
        }
        wm_redirect('comments.php');
    }

    wm_flash(false, '未知操作');
    wm_redirect('comments.php');
}
$comments = wm_all(
    'SELECT c.*, p.content AS post_content
     FROM ' . wm_t('comment') . ' c
     INNER JOIN ' . wm_t('post') . ' p ON p.id = c.post_id
     WHERE p.user_id = ? AND c.user_id <> ?
     ORDER BY c.id DESC
     LIMIT 100',
    [$userId, $userId]
);

$statusLabels = [
    0 => '待审核',
    1 => '已通过',
    2 => '已屏蔽',
];

user_head('收到的评论');
user_flash();
?>
<section class="panel">
    <div class="panel-title">
        <span>别人对我动态的评论</span>
        <span class="muted">共 <?= count($comments) ?> 条</span>
    </div>

    <table>
        <thead>
        <tr>
            <th>评论者</th>
            <th>评论内容</th>
            <th>我的动态</th>
            <th>状态</th>
            <th>时间</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$comments): ?>
            <tr><td colspan="6" class="muted">暂时没有收到评论</td></tr>
        <?php endif; ?>

        <?php foreach ($comments as $comment): ?>
            <tr>
                <td>
                    <?= e((string) $comment['nickname']) ?>
                    <?php if ((string) $comment['email'] !== ''): ?>
                        <div class="muted"><?= e(wm_cut((string) $comment['email'], 18)) ?></div>
                    <?php endif; ?>
                </td>
                <td class="comment-content"><?= e((string) $comment['content']) ?></td>
                <td><?= e(wm_cut((string) $comment['post_content'], 24)) ?></td>
                <td><?= e($statusLabels[(int) $comment['status']] ?? '未知') ?></td>
                <td><?= e(date('m-d H:i', strtotime((string) $comment['created_at']))) ?></td>
                <td class="comment-actions">
                    <?php if ((int) $comment['status'] === 0): ?>
                        <details class="review-menu">
                            <summary class="secondary">审核</summary>
                            <div class="review-options">
                                <form method="post">
                                    <?= wm_csrf_field() ?>
                                    <input type="hidden" name="act" value="moderate">
                                    <input type="hidden" name="id" value="<?= (int) $comment['id'] ?>">
                                    <input type="hidden" name="status" value="1">
                                    <button type="submit">通过</button>
                                </form>
                                <form method="post">
                                    <?= wm_csrf_field() ?>
                                    <input type="hidden" name="act" value="moderate">
                                    <input type="hidden" name="id" value="<?= (int) $comment['id'] ?>">
                                    <input type="hidden" name="status" value="2">
                                    <button class="reject" type="submit">不通过</button>
                                </form>
                            </div>
                        </details>
                    <?php else: ?>
                        <span class="status-text">已处理</span>
                    <?php endif; ?>
                    <a class="secondary" href="comments.php?reply_id=<?= (int) $comment['id'] ?>">回复</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php if ($replyTarget !== null): ?>
    <section class="panel reply-panel">
        <div class="panel-title">
            <span>回复评论</span>
            <a class="muted" href="comments.php">取消</a>
        </div>
        <p class="reply-context">
            正在回复 <?= e((string) $replyTarget['nickname']) ?> 的评论
        </p>
        <form method="post" class="form">
            <?= wm_csrf_field() ?>
            <input type="hidden" name="act" value="reply">
            <input type="hidden" name="id" value="<?= (int) $replyTarget['id'] ?>">
            <label>
                回复内容
                <textarea name="content" rows="4" maxlength="500" required placeholder="请输入回复内容"></textarea>
            </label>
            <button class="primary" type="submit">发布回复</button>
        </form>
    </section>
<?php endif; ?>
<?php user_foot();
