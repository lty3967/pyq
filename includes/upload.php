<?php
/**
 * 上传处理：严格 MIME/扩展白名单、图像重编码、视频头校验、随机文件名
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

const WM_VIDEO_EXT = ['mp4', 'webm'];

function wm_upload_limit_image(): int
{
    return max(1, (int)wm_setting('max_image_mb', '10')) * 1024 * 1024;
}

function wm_upload_limit_video(): int
{
    return max(1, (int)wm_setting('max_video_mb', '40')) * 1024 * 1024;
}

/**
 * 允许解压的最大像素数。
 * GD 以 truecolor 处理，内存占用约为「宽×高×4 字节」；
 * 原先固定 8000 万像素 ≈ 320MB，远超常见 memory_limit，
 * 一张高压缩比的小体积 JPEG 就能把 PHP 进程打爆（解压炸弹）。
 * 这里按 memory_limit 的一部分反推，并夹在合理区间内。
 */
function wm_image_max_pixels(): int
{
    $limit = trim((string)ini_get('memory_limit'));
    $bytes = 0;
    if ($limit !== '' && $limit !== '-1') {
        $unit = strtolower(substr($limit, -1));
        $num = (float)$limit;
        $mul = $unit === 'g' ? 1073741824 : ($unit === 'm' ? 1048576 : ($unit === 'k' ? 1024 : 1));
        $bytes = (int)($num * $mul);
    }
    // 无限制时取上限；否则只拿出 memory_limit 的一半给解压
    $byMem = $bytes > 0 ? (int)floor($bytes * 0.5 / 4) : 40000000;
    return max(5000000, min(40000000, $byMem));
}

/** 上传错误码转文案 */
function wm_upload_err(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:  return '文件超出服务器允许的大小';
        case UPLOAD_ERR_PARTIAL:    return '文件上传不完整';
        case UPLOAD_ERR_NO_FILE:    return '未选择文件';
        case UPLOAD_ERR_NO_TMP_DIR: return '服务器缺少临时目录';
        case UPLOAD_ERR_CANT_WRITE: return '服务器无法写入文件';
        case UPLOAD_ERR_EXTENSION:  return '上传被扩展阻止';
        default:                    return '上传失败';
    }
}

/** 按日期生成相对存储目录，并确保存在 */
function wm_upload_dir(string $type): array
{
    $sub = $type === 'video' ? 'video' : 'image';
    $rel = 'uploads/' . $sub . '/' . date('Y/m');
    $abs = WM_ROOT . '/' . $rel;
    if (!is_dir($abs) && !@mkdir($abs, 0755, true) && !is_dir($abs)) {
        return ['', ''];
    }
    return [$rel, $abs];
}

/**
 * 处理图片上传：重编码剥离元数据与潜在嵌入代码
 * @return array{ok:bool,msg:string,path?:string,width?:int,height?:int,size?:int,thumb?:string}
 */
function wm_upload_image(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'msg' => '非法上传参数'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => wm_upload_err((int)$file['error'])];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'msg' => '非法上传来源'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) { return ['ok' => false, 'msg' => '空文件']; }
    if ($size > wm_upload_limit_image()) {
        return ['ok' => false, 'msg' => '图片超过 ' . wm_setting('max_image_mb', '10') . 'MB 限制'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        return ['ok' => false, 'msg' => '不是有效的图片文件'];
    }
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    $mime = (string)($info['mime'] ?? '');
    $map = [
        'image/jpeg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($map[$mime])) {
        return ['ok' => false, 'msg' => '仅支持 JPG/PNG/GIF/WEBP 图片'];
    }
    $ext = $map[$mime];
    // 像素上限按 memory_limit 反推，避免解压阶段内存耗尽
    $maxPixels = wm_image_max_pixels();
    if ($w * $h > $maxPixels) {
        return ['ok' => false, 'msg' => '图片像素过大（上限约 ' . round($maxPixels / 1000000, 1) . ' 百万像素）'];
    }

    [$rel, $abs] = wm_upload_dir('image');
    if ($rel === '') { return ['ok' => false, 'msg' => '存储目录创建失败']; }

    $name = date('YmdHis') . '_' . wm_random(12) . '.' . $ext;
    $target = $abs . '/' . $name;

    $isAnimatedGif = ($ext === 'gif' && wm_gif_is_animated($file['tmp_name']));
    $ok = false;
    if ($isAnimatedGif) {
        // 动图保留原始数据（已通过 getimagesize 校验），仅移动
        $ok = @move_uploaded_file($file['tmp_name'], $target);
    } else {
        $ok = wm_image_reencode($file['tmp_name'], $target, $ext, $w, $h);
        if (!$ok) {
            $ok = @move_uploaded_file($file['tmp_name'], $target);
        }
    }
    if (!$ok || !is_file($target)) {
        return ['ok' => false, 'msg' => '文件保存失败'];
    }
    @chmod($target, 0644);

    $final = @getimagesize($target);
    if ($final !== false) {
        $w = (int)$final[0];
        $h = (int)$final[1];
    }

    $thumbRel = wm_image_thumb($target, $ext);

    return [
        'ok' => true, 'msg' => 'ok',
        'path' => $rel . '/' . $name,
        'thumb' => $thumbRel,
        'width' => $w, 'height' => $h,
        'size' => (int)filesize($target),
    ];
}

/** 判断 GIF 是否为动图 */
function wm_gif_is_animated(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) { return false; }
    $count = 0;
    $chunk = '';
    while (!feof($fh) && $count < 2) {
        $chunk = substr($chunk, -20) . (string)fread($fh, 8192);
        $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk);
    }
    fclose($fh);
    return $count > 1;
}

/** 重编码图片（剥离 EXIF / 嵌入内容），必要时等比缩放 */
function wm_image_reencode(string $src, string $dst, string $ext, int $w, int $h): bool
{
    if (!function_exists('imagecreatetruecolor')) { return false; }
    $maxSide = max(320, (int)wm_setting('image_max_side', '2000'));
    try {
        $img = wm_image_load($src, $ext);
        if ($img === null) { return false; }
        $scale = 1.0;
        if ($w > $maxSide || $h > $maxSide) {
            $scale = min($maxSide / $w, $maxSide / $h);
        }
        $nw = max(1, (int)floor($w * $scale));
        $nh = max(1, (int)floor($h * $scale));

        $out = imagecreatetruecolor($nw, $nh);
        if ($out === false) { imagedestroy($img); return false; }
        if ($ext === 'png' || $ext === 'webp' || $ext === 'gif') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            $trans = imagecolorallocatealpha($out, 0, 0, 0, 127);
            if ($trans !== false) { imagefilledrectangle($out, 0, 0, $nw, $nh, $trans); }
        } else {
            $white = imagecolorallocate($out, 255, 255, 255);
            if ($white !== false) { imagefilledrectangle($out, 0, 0, $nw, $nh, $white); }
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);

        $r = false;
        if ($ext === 'jpg') { $r = imagejpeg($out, $dst, (int)wm_setting('image_quality', '86')); }
        elseif ($ext === 'png') { $r = imagepng($out, $dst, 7); }
        elseif ($ext === 'gif') { $r = imagegif($out, $dst); }
        elseif ($ext === 'webp' && function_exists('imagewebp')) { $r = imagewebp($out, $dst, (int)wm_setting('image_quality', '86')); }
        imagedestroy($out);
        return (bool)$r;
    } catch (Throwable $e) {
        error_log('reencode fail: ' . $e->getMessage());
        return false;
    }
}

function wm_image_load(string $src, string $ext)
{
    switch ($ext) {
        case 'jpg':  return function_exists('imagecreatefromjpeg') ? (@imagecreatefromjpeg($src) ?: null) : null;
        case 'png':  return function_exists('imagecreatefrompng') ? (@imagecreatefrompng($src) ?: null) : null;
        case 'gif':  return function_exists('imagecreatefromgif') ? (@imagecreatefromgif($src) ?: null) : null;
        case 'webp': return function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($src) ?: null) : null;
    }
    return null;
}

/** 生成方形缩略图，返回相对路径或 '' */
function wm_image_thumb(string $absSrc, string $ext): string
{
    if (!function_exists('imagecreatetruecolor')) { return ''; }
    $size = max(80, (int)wm_setting('thumb_size', '400'));
    $rel = 'uploads/thumb/' . date('Y/m');
    $abs = WM_ROOT . '/' . $rel;
    if (!is_dir($abs) && !@mkdir($abs, 0755, true) && !is_dir($abs)) { return ''; }
    $name = pathinfo($absSrc, PATHINFO_FILENAME) . '_t.' . ($ext === 'webp' ? 'webp' : ($ext === 'png' ? 'png' : 'jpg'));
    $dst = $abs . '/' . $name;
    try {
        $info = @getimagesize($absSrc);
        if ($info === false) { return ''; }
        $img = wm_image_load($absSrc, $ext);
        if ($img === null) { return ''; }
        $w = (int)$info[0]; $h = (int)$info[1];
        $side = min($w, $h);
        $sx = (int)floor(($w - $side) / 2);
        $sy = (int)floor(($h - $side) / 2);
        $out = imagecreatetruecolor($size, $size);
        if ($out === false) { imagedestroy($img); return ''; }
        if ($ext === 'png' || $ext === 'webp') {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        } else {
            $white = imagecolorallocate($out, 245, 245, 245);
            if ($white !== false) { imagefilledrectangle($out, 0, 0, $size, $size, $white); }
        }
        imagecopyresampled($out, $img, 0, 0, $sx, $sy, $size, $size, $side, $side);
        imagedestroy($img);
        $ok = false;
        if ($ext === 'png') { $ok = imagepng($out, $dst, 7); }
        elseif ($ext === 'webp' && function_exists('imagewebp')) { $ok = imagewebp($out, $dst, 82); }
        else { $ok = imagejpeg($out, $dst, 82); }
        imagedestroy($out);
        if (!$ok) { return ''; }
        @chmod($dst, 0644);
        return $rel . '/' . $name;
    } catch (Throwable $e) {
        error_log('thumb fail: ' . $e->getMessage());
        return '';
    }
}

/**
 * 处理视频上传：容器头校验 + 扩展白名单
 */
function wm_upload_video(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'msg' => '非法上传参数'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'msg' => wm_upload_err((int)$file['error'])];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'msg' => '非法上传来源'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) { return ['ok' => false, 'msg' => '空文件']; }
    if ($size > wm_upload_limit_video()) {
        return ['ok' => false, 'msg' => '视频超过 ' . wm_setting('max_video_mb', '40') . 'MB 限制'];
    }

    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, WM_VIDEO_EXT, true)) {
        return ['ok' => false, 'msg' => '仅支持 MP4/WEBM 视频'];
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi !== false) {
            $mime = (string)finfo_file($fi, $file['tmp_name']);
            finfo_close($fi);
        }
    }
    $allowMime = ['video/mp4', 'video/webm', 'application/octet-stream', 'video/quicktime'];
    if ($mime !== '' && !in_array($mime, $allowMime, true)) {
        return ['ok' => false, 'msg' => '视频格式不被支持（' . e($mime) . '）'];
    }
    if (!wm_video_header_ok($file['tmp_name'], $ext)) {
        return ['ok' => false, 'msg' => '视频文件内容校验失败'];
    }

    [$rel, $abs] = wm_upload_dir('video');
    if ($rel === '') { return ['ok' => false, 'msg' => '存储目录创建失败']; }
    $name = date('YmdHis') . '_' . wm_random(12) . '.' . $ext;
    $target = $abs . '/' . $name;
    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        return ['ok' => false, 'msg' => '文件保存失败'];
    }
    @chmod($target, 0644);
    return [
        'ok' => true, 'msg' => 'ok',
        'path' => $rel . '/' . $name,
        'size' => (int)filesize($target),
        'width' => 0, 'height' => 0, 'thumb' => '',
    ];
}

/** 视频容器头校验 */
function wm_video_header_ok(string $path, string $ext): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) { return false; }
    $head = (string)fread($fh, 32);
    fclose($fh);
    if (strlen($head) < 12) { return false; }
    if ($ext === 'mp4') {
        // ISO BMFF: 4字节长度 + 'ftyp'
        return substr($head, 4, 4) === 'ftyp';
    }
    if ($ext === 'webm') {
        // EBML magic 1A 45 DF A3
        return substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
    }
    return false;
}

/**
 * 通用上传接收（用户端 upload.php 与后台 upload.php 共用）
 * 只负责「类型校验 + 落盘 + 统一返回结构」，鉴权与是否入库 media 由调用方决定
 * @return array{ok:bool,msg:string,data?:array}
 */
function wm_upload_receive(string $type, array $file): array
{
    if (!in_array($type, ['image', 'video'], true)) {
        return ['ok' => false, 'msg' => '上传类型无效'];
    }
    $res = $type === 'image' ? wm_upload_image($file) : wm_upload_video($file);
    if (!$res['ok']) {
        return ['ok' => false, 'msg' => (string)$res['msg']];
    }
    return [
        'ok'  => true,
        'msg' => '上传成功',
        'data' => [
            'type'   => $type,
            'path'   => (string)$res['path'],
            'thumb'  => (string)($res['thumb'] ?? ''),
            'width'  => (int)($res['width'] ?? 0),
            'height' => (int)($res['height'] ?? 0),
            'size'   => (int)$res['size'],
        ],
    ];
}

/** 删除媒体文件（限定在 uploads 目录内） */
function wm_media_unlink(string $relPath): bool
{
    $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
    if ($relPath === '' || strpos($relPath, '..') !== false) { return false; }
    if (strpos($relPath, 'uploads/') !== 0) { return false; }
    $abs = WM_ROOT . '/' . $relPath;
    $real = realpath($abs);
    $base = realpath(WM_UPLOAD);
    if ($real === false || $base === false || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }
    return @unlink($real);
}
