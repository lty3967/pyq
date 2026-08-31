<?php
/**
 * 通用函数库
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

/** HTML 转义输出 */
function e($str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** JS 上下文安全输出（用于内联 JSON） */
function ejs($data): string
{
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** 统一 JSON 响应并终止 */
function wm_json(bool $ok, string $msg = '', array $data = [], int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => $ok, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 取 GET/POST 字符串并规范化 */
function wm_input(string $key, string $method = 'POST', string $default = ''): string
{
    $src = $method === 'GET' ? $_GET : ($method === 'POST' ? $_POST : $_REQUEST);
    if (!isset($src[$key]) || !is_scalar($src[$key])) {
        return $default;
    }
    $v = (string)$src[$key];
    // 移除除 \n \r \t 以外的控制字符
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
    if ($v === null) { return $default; }
    return trim($v);
}

function wm_input_int(string $key, string $method = 'POST', int $default = 0): int
{
    $src = $method === 'GET' ? $_GET : ($method === 'POST' ? $_POST : $_REQUEST);
    if (!isset($src[$key]) || !is_scalar($src[$key])) {
        return $default;
    }
    $v = filter_var((string)$src[$key], FILTER_VALIDATE_INT);
    return $v === false ? $default : (int)$v;
}

/** 获取真实客户端 IP */
function wm_client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $trustProxy = (string)wm_setting('trust_proxy', '0') === '1';
    if ($trustProxy) {
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '') {
            $parts = explode(',', $xff);
            $ip = trim($parts[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        $xri = $_SERVER['HTTP_X_REAL_IP'] ?? '';
        if ($xri !== '' && filter_var($xri, FILTER_VALIDATE_IP)) {
            return $xri;
        }
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
}

/** IP 归一化为哈希 */
function wm_ip_hash(string $ip = ''): string
{
    $ip = $ip !== '' ? $ip : wm_client_ip();
    $salt = defined('AUTH_SALT') ? AUTH_SALT : 'wm';
    return substr(hash_hmac('sha256', $ip, $salt), 0, 32);
}

/** 微信风格相对时间 */
function wm_time_ago($ts): string
{
    $ts = is_numeric($ts) ? (int)$ts : (int)strtotime((string)$ts);
    if ($ts <= 0) { return ''; }
    $diff = time() - $ts;
    if ($diff < 0) { return date('Y-m-d H:i', $ts); }
    if ($diff < 60) { return '刚刚'; }
    if ($diff < 3600) { return floor($diff / 60) . '分钟前'; }
    if ($diff < 86400) { return floor($diff / 3600) . '小时前'; }
    if ($diff < 172800) { return '昨天 ' . date('H:i', $ts); }
    if ($diff < 2592000) { return floor($diff / 86400) . '天前'; }
    if (date('Y') === date('Y', $ts)) { return date('n月j日', $ts); }
    return date('Y年n月j日', $ts);
}

/** 字节 */
function wm_size(float $bytes, int $dec = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $dec) . ' ' . $units[$i];
}

/** 安全重定向：仅允许站内相对路径 */
function wm_redirect(string $path): void
{
    if (preg_match('#^(https?:)?//#i', $path) || strpos($path, "\r") !== false || strpos($path, "\n") !== false) {
        $path = 'index.php';
    }
    header('Location: ' . $path);
    exit;
}

/** 生成随机十六进制串 */
function wm_random(int $len = 16): string
{
    return bin2hex(random_bytes(max(1, (int)ceil($len / 2))));
}

/** 文本转安全 HTML：转义 + 换行 + 自动链接 */
function wm_text_html(string $text): string
{
    $safe = e($text);
    $safe = preg_replace_callback(
        '#\b(https?://[A-Za-z0-9\-\._~:/\?\#\[\]@!\$&\'\(\)\*\+,;=%]+)#',
        static function ($m) {
            $url = $m[1];
            return '<a href="' . $url . '" target="_blank" rel="nofollow noopener noreferrer">' . $url . '</a>';
        },
        $safe
    );
    return nl2br((string)$safe, false);
}

/** 截断文本 */
function wm_cut(string $text, int $len = 60): string
{
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '…' : $text;
}

/** 分页 HTML */
function wm_pager(int $total, int $page, int $size, string $baseQuery = ''): string
{
    $pages = (int)max(1, (int)ceil($total / max(1, $size)));
    if ($pages <= 1) { return ''; }
    $page = min(max(1, $page), $pages);
    $q = $baseQuery !== '' ? e($baseQuery) . '&amp;' : '';
    $html = '<div class="pager">';
    $mk = static function (int $p, string $label, bool $cur = false) use ($q) {
        if ($cur) { return '<span class="cur">' . e($label) . '</span>'; }
        return '<a href="?' . $q . 'page=' . $p . '">' . e($label) . '</a>';
    };
    if ($page > 1) { $html .= $mk($page - 1, '上一页'); }
    $start = max(1, $page - 2);
    $end = min($pages, $page + 2);
    if ($start > 1) { $html .= $mk(1, '1'); if ($start > 2) { $html .= '<span class="gap">…</span>'; } }
    for ($i = $start; $i <= $end; $i++) { $html .= $mk($i, (string)$i, $i === $page); }
    if ($end < $pages) { if ($end < $pages - 1) { $html .= '<span class="gap">…</span>'; } $html .= $mk($pages, (string)$pages); }
    if ($page < $pages) { $html .= $mk($page + 1, '下一页'); }
    $html .= '<span class="total">共 ' . $total . ' 条</span></div>';
    return $html;
}

/** 目录占用 */
function wm_dir_size(string $dir): int
{
    if (!is_dir($dir)) { return 0; }
    $size = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if ($f->isFile()) { $size += $f->getSize(); }
        }
    } catch (Throwable $e) {
        error_log('dir size fail: ' . $e->getMessage());
    }
    return $size;
}

/** 写操作日志 */
/** 设置跨请求的一次性提示消息 */
function wm_flash(bool $ok, string $msg): void
{
    $_SESSION['_flash'] = [
        'ok' => $ok,
        'msg' => $msg,
    ];
}

function wm_log(string $action, string $detail = '', int $adminId = 0): void
{
    try {
        wm_exec('INSERT INTO ' . wm_t('log') . ' (admin_id, action, detail, ip, ua, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())', [
            $adminId ?: (int)($_SESSION['admin_id'] ?? 0),
            mb_substr($action, 0, 50),
            mb_substr($detail, 0, 500),
            wm_client_ip(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('log fail: ' . $e->getMessage());
    }
}
