<?php
declare(strict_types=1);

require __DIR__ . '/layout.php';

$id = wm_input_int('id', 'GET', 0);
$userId = (int) $user['id'];
$post = null;
$media = [];
$error = '';

if ($id > 0) {
    $post = wm_one(
        'SELECT * FROM ' . wm_t('post') . ' WHERE id = ? AND user_id = ? LIMIT 1',
        [$id, $userId]
    );

    if ($post === null) {
        wm_flash(false, '动态不存在或无权访问');
        wm_redirect('index.php');
    }

    $media = wm_all(
        'SELECT * FROM ' . wm_t('media') . ' WHERE post_id = ? AND user_id = ? ORDER BY sort, id',
        [$id, $userId]
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();

    $content = wm_input('content');
    $categoryId = wm_input_int('cat_id');
    $location = wm_input('location');
    $status = wm_input_int('status') === 1 ? 1 : 0;
    $allowComment = wm_input_int('allow_comment') === 1 ? 1 : 0;

    if ($content === '' && !$media) {
        $error = '请填写文字或上传媒体';
    } elseif (mb_strlen($content) > 5000 || mb_strlen($location) > 50) {
        $error = '内容或位置超出长度限制';
    } elseif ($categoryId > 0 && wm_value(
        'SELECT id FROM ' . wm_t('category') . ' WHERE id = ? AND status = 1',
        [$categoryId]
    ) === null) {
        $error = '分类无效';
    }

    $rawMedia = json_decode((string) ($_POST['media_data'] ?? '[]'), true);
    $selectedMedia = [];

    if (is_array($rawMedia)) {
        foreach ($rawMedia as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }

            $mediaRow = wm_one(
                'SELECT * FROM ' . wm_t('media') . '
                 WHERE id = ? AND user_id = ? AND (post_id = 0 OR post_id = ?)
                 LIMIT 1',
                [(int) $item['id'], $userId, $id]
            );

            if ($mediaRow !== null) {
                $selectedMedia[] = $mediaRow;
            }

            if (count($selectedMedia) >= 9) {
                break;
            }
        }
    }

    $videoMedia = array_values(array_filter(
        $selectedMedia,
        static fn(array $item): bool => $item['type'] === 'video'
    ));

    if ($videoMedia) {
        $selectedMedia = [$videoMedia[0]];
    }

    if ($content === '' && !$selectedMedia) {
        $error = '请填写文字或上传媒体';
    }

    if ($error === '') {
        $mediaType = 'none';
        if ($selectedMedia) {
            $mediaType = $selectedMedia[0]['type'] === 'video' ? 'video' : 'image';
        }

        $db = wm_db();
        $removedMedia = [];

        try {
            $db->beginTransaction();

            if ($id > 0) {
                $oldMedia = wm_all(
                    'SELECT id, path, thumb FROM ' . wm_t('media') . ' WHERE post_id = ? AND user_id = ?',
                    [$id, $userId]
                );

                wm_exec(
                    'UPDATE ' . wm_t('media') . ' SET post_id = 0 WHERE post_id = ? AND user_id = ?',
                    [$id, $userId]
                );

                wm_exec(
                    'UPDATE ' . wm_t('post') . '
                     SET cat_id = ?, content = ?, media_type = ?, location = ?, status = ?, allow_comment = ?
                     WHERE id = ? AND user_id = ?',
                    [$categoryId, $content, $mediaType, $location, $status, $allowComment, $id, $userId]
                );

                $postId = $id;
                $selectedPaths = array_column($selectedMedia, 'path');
                foreach ($oldMedia as $old) {
                    if (!in_array($old['path'], $selectedPaths, true)) {
                        $removedMedia[] = $old;
                    }
                }

                if ($removedMedia) {
                    $removedIds = array_column($removedMedia, 'id');
                    $placeholders = implode(',', array_fill(0, count($removedIds), '?'));
                    wm_exec(
                        'DELETE FROM ' . wm_t('media') . ' WHERE id IN (' . $placeholders . ') AND user_id = ?',
                        array_merge(array_map('intval', $removedIds), [$userId])
                    );
                }
            } else {
                wm_exec(
                    'INSERT INTO ' . wm_t('post') . '
                     (user_id, cat_id, content, media_type, location, status, allow_comment, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$userId, $categoryId, $content, $mediaType, $location, $status, $allowComment]
                );

                $postId = wm_insert_id();
            }

            foreach ($selectedMedia as $sort => $item) {
                wm_exec(
                    'UPDATE ' . wm_t('media') . '
                     SET post_id = ?, sort = ?
                     WHERE id = ? AND user_id = ? AND post_id = 0',
                    [$postId, $sort, (int) $item['id'], $userId]
                );
            }

            $db->commit();

            foreach ($removedMedia as $old) {
                wm_media_unlink((string) $old['path']);
                if ((string) $old['thumb'] !== '') {
                    wm_media_unlink((string) $old['thumb']);
                }
            }

            wm_flash(true, $id > 0 ? '动态已更新' : '动态已发布');
            wm_redirect('index.php');
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('user post save fail: ' . $exception->getMessage());
            $error = '保存失败，请稍后重试';
        }
    }
}

$categories = wm_categories(true);
$mediaData = array_map(
    static fn(array $item): array => [
        'id' => (int) $item['id'],
        'type' => $item['type'],
        'path' => $item['path'],
        'thumb' => $item['thumb'],
        'width' => (int) $item['width'],
        'height' => (int) $item['height'],
        'size' => (int) $item['size'],
    ],
    $media
);

user_head($id > 0 ? '编辑动态' : '发布动态');
user_flash();
?>
<?php if ($error !== ''): ?>
    <div class="alert err"><?= e($error) ?></div>
<?php endif; ?>

<section class="panel">
    <form method="post" class="form" id="userPostForm">
        <?= wm_csrf_field() ?>

        <label>
            内容
            <textarea name="content" maxlength="5000" rows="7" placeholder="分享这一刻…"><?= e((string) ($post['content'] ?? '')) ?></textarea>
        </label>

        <label>
            图片 / 视频
            <div class="upload-box" id="userUploader">
                <div class="upload-list" id="uploadList"></div>
                <div class="upload-actions">
                    <button type="button" id="userImgBtn">添加图片</button>
                    <button type="button" id="userVidBtn">添加视频</button>
                    <span id="uploadState" class="muted"></span>
                </div>
                <input id="userImg" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
                <input id="userVid" type="file" accept="video/mp4,video/webm" hidden>
            </div>
        </label>

        <label>
            分类
            <select name="cat_id">
                <option value="0">未分类</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= (int) ($post['cat_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>>
                        <?= e((string) $category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            位置
            <input name="location" maxlength="50" value="<?= e((string) ($post['location'] ?? '')) ?>" placeholder="选填">
        </label>

        <label class="check">
            <input type="checkbox" name="status" value="1" <?= (int) ($post['status'] ?? 1) === 1 ? 'checked' : '' ?>>
            立即发布
        </label>

        <label class="check">
            <input type="checkbox" name="allow_comment" value="1" <?= (int) ($post['allow_comment'] ?? 1) === 1 ? 'checked' : '' ?>>
            允许评论
        </label>

        <input type="hidden" name="media_data" id="userMediaData" value="<?= e(json_encode($mediaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]') ?>">
        <button class="primary" type="submit">保存动态</button>
    </form>
</section>

<script>
    window.WM_USER = <?= ejs(['token' => wm_csrf_token()]) ?>;
</script>
<script src="../assets/js/user.js?v=<?= e(WM_VERSION) ?>"></script>
<?php user_foot();
