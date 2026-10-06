<?php
/**
 * 后台上传接口（图片 / 视频）
 */
declare(strict_types=1);
require dirname(__DIR__) . '/includes/init.php';
require_once WM_INC . '/upload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

wm_require_post(true);
// 后台上传端点只服务管理员：前台用户请走 user/upload.php。
// 原先允许任意登录用户调用，可被用来向磁盘灌文件但不产生任何业务数据。
$admin = wm_require_admin(true);
wm_csrf_check(true);

$actorKey = 'admin' . (int)$admin['id'];
if (!wm_rate_limit('upload', 200, 3600, $actorKey)) {
    wm_json(false, '上传过于频繁，请稍后再试', [], 429);
}

$type = wm_input('type');
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    wm_json(false, '未接收到文件', [], 400);
}

// 类型校验 + 落盘 + 返回结构统一走公共函数（与用户端 upload.php 共用）
// 差异：后台不登记 media 表，媒体在保存动态时随 post 一起入库
$res = wm_upload_receive($type, $_FILES['file']);
if (!$res['ok']) {
    wm_json(false, (string)$res['msg'], [], 400);
}
$data = $res['data'];

wm_log('上传媒体', $data['type'] . '：' . $data['path'] . '（' . wm_size((float)$data['size']) . '）', (int)$admin['id']);

wm_json(true, '上传成功', $data);
