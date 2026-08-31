<?php
/**
 * 后台上传接口（图片 / 视频）
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';
require WM_INC . '/upload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

wm_require_post(true);
$admin = wm_admin();
$user = wm_user();
if ($admin === null && $user === null) {
    wm_json(false, '登录状态已失效，请重新登录', ['relogin' => true], 401);
}
wm_csrf_check(true);

$actorKey = $admin !== null ? 'admin' . (int)$admin['id'] : 'user' . (int)$user['id'];
if (!wm_rate_limit('upload', 200, 3600, $actorKey)) {
    wm_json(false, '上传过于频繁，请稍后再试', [], 429);
}

$type = wm_input('type');
if (!in_array($type, ['image', 'video'], true)) {
    wm_json(false, '上传类型无效', [], 400);
}
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    wm_json(false, '未接收到文件', [], 400);
}

$res = $type === 'image' ? wm_upload_image($_FILES['file']) : wm_upload_video($_FILES['file']);
if (!$res['ok']) {
    wm_json(false, (string)$res['msg'], [], 400);
}

wm_log('上传媒体', $type . '：' . $res['path'] . '（' . wm_size((float)$res['size']) . '）', $admin !== null ? (int)$admin['id'] : 0);

wm_json(true, '上传成功', [
    'type'   => $type,
    'path'   => (string)$res['path'],
    'thumb'  => (string)($res['thumb'] ?? ''),
    'width'  => (int)($res['width'] ?? 0),
    'height' => (int)($res['height'] ?? 0),
    'size'   => (int)$res['size'],
]);
