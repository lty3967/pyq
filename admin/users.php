<?php
/**
 * 后台 - 前台用户管理
 *
 * 支持启用/禁用与删除。删除为级联清理：
 *   用户动态 → 动态下的媒体、评论、点赞、浏览记录 → 用户自己的评论/点赞/
 *   未关联媒体（post_id = 0）→ 用户头像文件 → 用户记录。
 * 媒体文件在事务提交成功后才 unlink，避免回滚导致「记录还在、文件已丢」。
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$admin = wm_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();

    $action = wm_input('act');
    $userId = wm_input_int('id');
    $target = wm_one(
        'SELECT id, username, status, avatar FROM ' . wm_t('user') . ' WHERE id = ? LIMIT 1',
        [$userId]
    );

    if ($target === null) {
        wm_flash(false, '用户不存在');
        wm_redirect('users.php');
    }

    if ($action === 'status') {
        $nextStatus = (int) $target['status'] === 1 ? 0 : 1;
        wm_exec(
            'UPDATE ' . wm_t('user') . ' SET status = ? WHERE id = ?',
            [$nextStatus, $userId]
        );
        wm_log('修改用户状态', '用户 ID：' . $userId);
        wm_flash(true, $nextStatus === 1 ? '用户已启用' : '用户已禁用');
        wm_redirect('users.php');
    }

    if ($action === 'delete') {
        $db = wm_db();
        $mediaToDelete = [];

        try {
            $db->beginTransaction();

            $posts = wm_all(
                'SELECT id FROM ' . wm_t('post') . ' WHERE user_id = ?',
                [$userId]
            );

            foreach ($posts as $post) {
                $postId = (int) $post['id'];
                $mediaToDelete = array_merge(
                    $mediaToDelete,
                    wm_all(
                        'SELECT path, thumb FROM ' . wm_t('media') . ' WHERE post_id = ?',
                        [$postId]
                    )
                );

                wm_exec('DELETE FROM ' . wm_t('media') . ' WHERE post_id = ?', [$postId]);
                wm_exec('DELETE FROM ' . wm_t('comment') . ' WHERE post_id = ?', [$postId]);
                wm_exec('DELETE FROM ' . wm_t('like') . ' WHERE post_id = ?', [$postId]);
                wm_exec('DELETE FROM ' . wm_t('view') . ' WHERE post_id = ?', [$postId]);
                wm_exec('DELETE FROM ' . wm_t('post') . ' WHERE id = ?', [$postId]);
            }

            // 未关联到动态的上传（post_id = 0）也要收集，
            // 否则下面删了数据库记录却把物理文件永远留在 uploads 里
            $mediaToDelete = array_merge(
                $mediaToDelete,
                wm_all(
                    'SELECT path, thumb FROM ' . wm_t('media') . ' WHERE user_id = ? AND post_id = 0',
                    [$userId]
                )
            );

            wm_exec('DELETE FROM ' . wm_t('comment') . ' WHERE user_id = ?', [$userId]);
            wm_exec('DELETE FROM ' . wm_t('like') . ' WHERE user_id = ?', [$userId]);
            wm_exec('DELETE FROM ' . wm_t('media') . ' WHERE user_id = ?', [$userId]);
            wm_exec('DELETE FROM ' . wm_t('user') . ' WHERE id = ?', [$userId]);

            $db->commit();

            foreach ($mediaToDelete as $media) {
                wm_media_unlink((string) $media['path']);
                if ((string) $media['thumb'] !== '') {
                    wm_media_unlink((string) $media['thumb']);
                }
            }

            // 用户头像不在 media 表中，需单独清理，否则会残留孤儿文件
            if ((string) $target['avatar'] !== '') {
                wm_media_unlink((string) $target['avatar']);
            }

            wm_log('删除用户', '用户 ID：' . $userId);
            wm_flash(true, '用户及其动态、评论、点赞已删除');
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('user delete fail: ' . $exception->getMessage());
            wm_flash(false, '删除失败，请稍后重试');
        }

        wm_redirect('users.php');
    }

    wm_flash(false, '未知操作');
    wm_redirect('users.php');
}

$page = max(1, wm_input_int('page', 'GET', 1));
$pageSize = 20;
$keyword = mb_substr(wm_input('kw', 'GET'), 0, 40);
$where = '';
$params = [];

if ($keyword !== '') {
    // 必须整体加括号：AND 优先级高于 OR，后续一旦追加其它条件就会静默产生错误结果
    $where = ' WHERE (u.username LIKE :keyword
               OR u.nickname LIKE :keyword
               OR u.email LIKE :keyword)';
    $params[':keyword'] = '%' . wm_like_escape($keyword) . '%';
}

$total = (int) wm_value(
    'SELECT COUNT(*) FROM ' . wm_t('user') . ' u' . $where,
    $params
);

$pg = wm_paging($total, $page, $pageSize);
$page = $pg['page'];
$offset = $pg['offset'];
$users = wm_all(
    'SELECT u.*,
            COALESCE((SELECT COUNT(*) FROM ' . wm_t('post') . ' p WHERE p.user_id = u.id), 0) AS posts,
            COALESCE((SELECT SUM(p.views) FROM ' . wm_t('post') . ' p WHERE p.user_id = u.id), 0) AS views,
            COALESCE((SELECT COUNT(*) FROM ' . wm_t('comment') . ' c WHERE c.user_id = u.id), 0) AS comments
     FROM ' . wm_t('user') . ' u' . $where . '
     ORDER BY u.id DESC
     LIMIT ' . $pageSize . ' OFFSET ' . $offset,
    $params
);

wm_head('用户管理');
?>
<form class="toolbar" method="get" action="users.php">
    <input class="inp" name="kw" maxlength="40" value="<?= e($keyword) ?>" placeholder="账号、昵称或邮箱">
    <button class="btn sm" type="submit">搜索</button>
    <span class="sp"></span>
    <span class="hint">共 <?= $total ?> 个用户</span>
</form>

<div class="box">
    <table class="tb">
        <thead>
        <tr>
            <th>ID</th>
            <th>账号</th>
            <th>昵称</th>
            <th>邮箱</th>
            <th>动态</th>
            <th>浏览</th>
            <th>评论</th>
            <th>状态</th>
            <th>注册时间</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
            <tr><td colspan="10" class="none">暂无用户</td></tr>
        <?php endif; ?>

        <?php foreach ($users as $user): ?>
            <tr>
                <td><?= (int) $user['id'] ?></td>
                <td><?= e((string) $user['username']) ?></td>
                <td><?= e((string) $user['nickname']) ?></td>
                <td><?= e((string) ($user['email'] ?: '—')) ?></td>
                <td><?= (int) $user['posts'] ?></td>
                <td><?= (int) $user['views'] ?></td>
                <td><?= (int) $user['comments'] ?></td>
                <td>
                    <?= (int) $user['status'] === 1
                        ? '<span class="st on">正常</span>'
                        : '<span class="st off">禁用</span>' ?>
                </td>
                <td><?= e((string) $user['created_at']) ?></td>
                <td class="acts">
                    <a class="btn sm ghost" href="user_edit.php?id=<?= (int) $user['id'] ?>">编辑</a>
                    <a class="btn sm ghost" href="posts.php?user_id=<?= (int) $user['id'] ?>">动态</a>

                    <form method="post" style="display:inline">
                        <?= wm_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <input type="hidden" name="act" value="status">
                        <button class="btn sm ghost" type="submit">
                            <?= (int) $user['status'] === 1 ? '禁用' : '启用' ?>
                        </button>
                    </form>

                    <form method="post" style="display:inline">
                        <?= wm_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                        <input type="hidden" name="act" value="delete">
                        <button class="btn sm danger" type="submit" data-confirm="确认删除该用户及其全部动态、评论、点赞和媒体？">
                            删除
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= wm_pager($total, $page, $pageSize, $keyword !== '' ? 'kw=' . urlencode($keyword) : '') ?>
<?php wm_foot();
