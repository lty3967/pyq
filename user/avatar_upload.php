<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/init.php';
require WM_INC . '/upload.php';

wm_require_post(true);
$user = wm_require_user(true);
wm_csrf_check(true);

if (!wm_rate_limit('user_avatar', 10, 3600, 'user' . (int) $user['id'])) {
    wm_json(false, '头像修改过于频繁，请稍后再试', [], 429);
}

if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
    wm_json(false, '请选择头像图片', [], 400);
}

$result = wm_upload_image($_FILES['avatar']);
if (!$result['ok']) {
    wm_json(false, (string) $result['msg'], [], 400);
}

$newPath = (string) $result['path'];
$oldPath = (string) ($user['avatar'] ?? '');

try {
    wm_exec(
        'UPDATE ' . wm_t('user') . ' SET avatar = ? WHERE id = ?',
        [$newPath, (int) $user['id']]
    );

    if ($oldPath !== '') {
        wm_media_unlink($oldPath);
    }

    wm_json(true, '头像已更新', [
        'path' => $newPath,
        'thumb' => (string) ($result['thumb'] ?? ''),
    ]);
} catch (Throwable $exception) {
    wm_media_unlink($newPath);
    if (!empty($result['thumb'])) {
        wm_media_unlink((string) $result['thumb']);
    }
    error_log('user avatar update fail: ' . $exception->getMessage());
    wm_json(false, '头像保存失败，请稍后重试', [], 500);
}
