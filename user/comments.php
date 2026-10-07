<?php
/**
 * 用户中心 - 收到的评论
 *
 * 权限口径：只处理「别人评论我的动态」，因此查询统一带
 *   p.user_id = ?（动态归属）AND c.user_id <> ?（排除自己的回复）。
 * 用户可对自己动态下的评论做通过 / 屏蔽，并以自己身份回复（回复会以 user_id 落库）。
 */
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

    if ($action === 'delete') {
        // 连同所有层级的子回复一并删除，避免多级回复残留成孤儿数据
        $delIds = wm_comment_descendants([$commentId]);
        $n = wm_exec('DELETE FROM ' . wm_t('comment') . ' WHERE id IN ('
            . implode(',', array_fill(0, count($delIds), '?')) . ')', $delIds);
        wm_post_resync((int) $comment['post_id']);
        wm_flash(true, '已删除该评论' . ($n > 1 ? '及其 ' . ($n - 1) . ' 条回复' : ''));
        wm_redirect('comments.php');
    }

    if ($action === 'reply') {
        $content = wm_input('content');
        $interval = max(0, (int) wm_setting('comment_interval', '30'));
        $maxHour = max(1, (int) wm_setting('comment_max_per_hour', '10'));
        $subject = 'user' . $userId;

        if ($content === '' || mb_strlen($content) > 500) {
            wm_flash(false, '回复内容需为 1-500 字');
        } elseif ($interval > 0 && !wm_rate_limit('cmt_i', 1, $interval, $subject)) {
            wm_flash(false, '发言过快，请 ' . $interval . ' 秒后再试');
        } elseif (!wm_rate_limit('cmt_h', $maxHour, 3600, $subject)) {
            wm_flash(false, '回复次数已达上限，请稍后再试');
        } else {
            // 敏感词：与前台评论保持同一套策略
            $hits = wm_badword_hit($content);
            $mode = (string) wm_setting('comment_mask_mode', 'reject');
            if ($hits && $mode === 'reject') {
                wm_flash(false, '内容包含违禁词，发布失败');
                wm_redirect('comments.php');
            }
            if ($hits && $mode === 'mask') {
                $content = wm_badword_mask($content);
            }
            $status = ($hits && $mode === 'audit') ? 0 : 1;

            $nickname = (string) ($user['nickname'] ?: $user['username']);
            wm_exec(
                'INSERT INTO ' . wm_t('comment') . ' (post_id, user_id, parent_id, nickname, email, content, status, ip, ip_hash, ua, bad_hit, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                [
                    (int) $comment['post_id'],
                    $userId,
                    $commentId,
                    $nickname,
                    (string) $user['email'],
                    $content,
                    $status,
                    wm_client_ip(),
                    wm_ip_hash(),
                    'user',
                    mb_substr(implode(',', $hits), 0, 200),
                ]
            );
            wm_post_resync((int) $comment['post_id']);
            wm_flash(true, $status === 1 ? '回复已发布' : '回复已提交，等待审核');
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
                <td class="status-cell">
                    <?php
                    // 与后台评论管理一致：彩色标签 + 统一的状态文案/样式
                    $st = wm_comment_status((int) $comment['status']);
                    ?>
                    <span class="st <?= e($st[1]) ?>"><?= e($st[0]) ?></span>
                </td>
                <td><?= e(date('m-d H:i', strtotime((string) $comment['created_at']))) ?></td>
                <td class="comment-actions">
                    <?php if ((int) $comment['status'] === 0): ?>
                        <details class="review-menu">
                            <summary class="btn sm ghost">审核</summary>
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
                    <?php endif; ?>
                    <a class="btn sm ghost" href="comments.php?reply_id=<?= (int) $comment['id'] ?>">回复</a>
                    <?php if ((int) $comment['status'] !== 0): ?>
                        <?php /* 待审时「不通过」已经覆盖了屏蔽，不再重复出一个同义按钮 */ ?>
                        <form method="post" class="inline-form">
                            <?= wm_csrf_field() ?>
                            <input type="hidden" name="act" value="moderate">
                            <input type="hidden" name="id" value="<?= (int) $comment['id'] ?>">
                            <input type="hidden" name="status" value="<?= (int) $comment['status'] === 2 ? 1 : 2 ?>">
                            <button class="btn sm ghost" type="submit"><?= (int) $comment['status'] === 2 ? '恢复' : '屏蔽' ?></button>
                        </form>
                    <?php endif; ?>
                    <form method="post" class="inline-form"
                          data-confirm="删除后不可恢复，该评论下的所有回复也会一并删除，确定删除？">
                        <?= wm_csrf_field() ?>
                        <input type="hidden" name="act" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $comment['id'] ?>">
                        <button class="btn sm danger" type="submit">删除</button>
                    </form>
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
                <textarea name="content" id="replyText" rows="4" maxlength="500" required placeholder="请输入回复内容，可点右侧按钮插入表情"></textarea>
            </label>
            <button type="button" class="secondary emoji-trigger" id="replyEmoji" aria-label="表情">😊 表情</button>
            <button class="primary" type="submit">发布回复</button>
        </form>
    </section>
<?php endif; ?>
<script src="../assets/js/emoji.js?v=<?= e(WM_ASSET_VER) ?>"></script>
<script>
    (function () {
        var ta = document.getElementById('replyText');
        var btn = document.getElementById('replyEmoji');
        if (ta && btn && window.WmEmoji) { window.WmEmoji.attach(ta, btn); }
    }());
</script>
<?php user_foot();
