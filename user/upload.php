<?php
/**
 * 用户端媒体上传接口（返回 JSON）
 *
 * 与后台 admin/upload.php 共用 wm_upload_receive() 做类型校验与落盘，
 * 差异在于：用户端上传即登记到 media 表（post_id = 0），
 * 发布动态时再通过 media id 关联；后台则不登记，随动态一起入库。
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';
require_once WM_INC . '/upload.php';

header('Content-Type: application/json; charset=utf-8');

wm_require_post(true);
$user = wm_require_user(true);
wm_csrf_check(true);

if (!wm_rate_limit('user_upload', 100, 3600, 'user' . (int) $user['id'])) {
    wm_json(false, '上传过于频繁，请稍后再试', [], 429);
}

$type = wm_input('type');
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    wm_json(false, '上传参数错误', [], 400);
}

// 类型校验 + 落盘 + 返回结构统一走公共函数（与后台 upload.php 共用）
// 差异仅在：用户端上传即登记到 media 表（post_id = 0，发布动态时再关联）
$res = wm_upload_receive($type, $_FILES['file']);
if (!$res['ok']) {
    wm_json(false, (string) $res['msg'], [], 400);
}
$d = $res['data'];

wm_exec(
    'INSERT INTO ' . wm_t('media') . ' (post_id, user_id, type, path, thumb, width, height, size, sort, created_at)
     VALUES (0, ?, ?, ?, ?, ?, ?, ?, 0, NOW())',
    [(int) $user['id'], $d['type'], $d['path'], $d['thumb'], $d['width'], $d['height'], $d['size']]
);
$mediaId = wm_insert_id();

wm_json(true, '上传成功', ['id' => $mediaId] + $d);
