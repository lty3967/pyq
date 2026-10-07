<?php
/**
 * 通用函数库
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

/**
 * HTML 转义输出
 *
 * 参数刻意不使用 string 强类型声明：模板中存在 e((int)$x)、e(null) 等调用，
 * 而调用方文件普遍 declare(strict_types=1)，声明 string 会直接抛 TypeError。
 * 因此用 @param mixed 标注语义，内部统一 (string) 转换，行为与声明前一致。
 *
 * @param mixed $str 标量或 null；数组/对象属于调用方错误，按空串处理
 * @return string
 */
function e($str): string
{
    // 数组与无 __toString 的对象直接转字符串会触发 Notice 并输出 "Array"，
    // 既污染页面又刷错误日志，这里降级为空串
    if (is_array($str) || (is_object($str) && !method_exists($str, '__toString'))) {
        return '';
    }
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * JS 上下文安全输出（用于内联 JSON）
 *
 * @param mixed $data 任意可 JSON 序列化的数据（模板里通常传入配置数组）
 * @return string 合法的 JS 字面量，可直接嵌入 <script>
 */
function ejs($data): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $json = json_encode($data, $flags);
    if ($json === false) {
        // json_encode 遇无效 UTF-8（如历史 GBK 残留数据）会失败返回 false，
        // 而返回类型声明为 string，直接返回 false 在 strict_types 下会抛 TypeError。
        // 先按替换字符重试一次，仍失败则降级为 null 字面量，
        // 保证 <script> 内始终是合法 JS，不会因一条脏数据整页崩溃。
        $json = json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    return $json === false ? 'null' : $json;
}

/** 统一 JSON 响应并终止 */
function wm_json(bool $ok, string $msg = '', array $data = [], int $code = 200): void
{
    // JSON 接口里任何多余输出（PHP 告警、弃用提示、调试残留）都会让前端
    // r.json() 直接抛错，只能显示「服务器响应异常」。这里先丢弃已产生的
    // 输出缓冲，保证响应体是纯 JSON。
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
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

/**
 * 判断某个 IP 是否属于「可信代理」（内网 / 回环 / 保留段）。
 * 只有来自可信代理的请求才允许采信 X-Forwarded-For。
 */
function wm_is_trusted_proxy(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }
    // FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE 会在 IP 属于私有或保留网段时返回 false，
    // 也就是「公网 IP 才返回真值」——取反即得到「是内网/保留地址」
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/** 获取真实客户端 IP */
function wm_client_ip(): string
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // 0 = 完全不信任；1 = 仅内网/回环来源才信任（默认，防伪造）；
    // 2 = 始终信任（Cloudflare / 独立反代服务器，此时 REMOTE_ADDR 是对方公网 IP）
    $trustProxy = (string)wm_setting('trust_proxy', '0');
    if ($trustProxy !== '0' && ($trustProxy === '2' || wm_is_trusted_proxy($remote))) {
        // 只有直连来源本身是内网/回环（说明请求确实经过了本机或内网的反向代理）时
        // 才采信 XFF。否则任何人发一个 X-Forwarded-For 头就能伪造 IP，
        // 进而绕过登录失败锁定、IP 黑名单与全部频率限制。
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

/**
 * 微信风格相对时间
 *
 * @param mixed $ts 时间戳，或可被 strtotime 解析的日期字符串（如数据库的 datetime）；
 *                  null / 非法值 / 无法解析的字符串一律返回空串
 * @return string
 */
function wm_time_ago($ts): string
{
    // 数组与无 __toString 的对象转字符串会触发 Notice，按非法值处理
    if (is_array($ts) || (is_object($ts) && !method_exists($ts, '__toString'))) {
        return '';
    }
    $ts = is_numeric($ts) ? (int)$ts : (int)strtotime((string)$ts);
    if ($ts <= 0) { return ''; }
    // date() 对超出 9999-12-31 的时间戳会返回 false，
    // 而返回类型声明为 string，返回 false 在 strict_types 下会抛 TypeError
    if ($ts > 253402300799) { return ''; }
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

/** 安全重定向：仅允许站内相对路径；统一补全为绝对 URL，避免个别 HTTP/2/CDN 环境对相对 Location 处理出错 */
function wm_redirect(string $path): void
{
    if (preg_match('#^(https?:)?//#i', $path) || strpos($path, "\r") !== false || strpos($path, "\n") !== false) {
        $path = 'index.php';
    }
    // 相对路径补全为基于当前脚本目录的绝对 URL（部分 HTTP/2/CDN 环境对相对 Location 处理异常）
    if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path)) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['SERVER_PORT'] ?? '') === '443') ? 'https' : 'http';
        $host = preg_match('/^[A-Za-z0-9.\-:\[\]]{1,255}$/', $_SERVER['HTTP_HOST'] ?? '')
            ? $_SERVER['HTTP_HOST'] : ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $dir = rtrim((string)dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
        $path = $scheme . '://' . $host . ($path[0] === '/' ? $path : $dir . '/' . $path);
    }
    // 若响应头已经发出（意外提前输出了内容），用 meta/JS 兜底跳转，避免返回损坏响应
    if (headers_sent()) {
        echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='
            . e($path) . '"><script>location.href=' . json_encode($path) . ';</script>';
        exit;
    }
    // 收尾：先清空所有输出缓冲（丢弃任何意外缓冲的警告文本，确保 header() 一定能发出），
    // 再落盘会话（避免 shutdown 阶段写会话时再产生输出导致 HTTP/2 流被截断/协议错误），
    // 最后显式 302 + 绝对 Location + 极简正文兜底。
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    http_response_code(302);
    header('Location: ' . $path);
    echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url='
        . e($path) . '"><title>正在跳转…</title><a href="' . e($path) . '">继续</a>';
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

/** 设置跨请求的一次性提示消息 */
function wm_flash(bool $ok, string $msg): void
{
    $_SESSION['_flash'] = [
        'ok' => $ok,
        'msg' => $msg,
    ];
}

/**
 * 取出并渲染一次性提示（用户中心与后台共用，取后即销毁）
 */
function wm_flash_html(): string
{
    if (empty($_SESSION['_flash']) || !is_array($_SESSION['_flash'])) {
        return '';
    }
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    $class = !empty($flash['ok']) ? 'ok' : 'err';
    return '<div class="alert ' . $class . '">' . e((string)$flash['msg']) . '</div>';
}

/** 写操作日志 */
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

/**
 * 统一分页计算：把越界页码收敛到最后一页。
 * 不做收敛的话 ?page=999999 会生成巨大 OFFSET，MySQL 需扫描并丢弃大量行，
 * 是一个低成本的拒绝服务入口。
 * @return array{page:int,pages:int,offset:int}
 */
function wm_paging(int $total, int $page, int $size): array
{
    $size = max(1, $size);
    $pages = (int)max(1, (int)ceil($total / $size));
    $page = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $size];
}

/**
 * LIKE 通配符转义：反斜杠必须最先处理，否则会吞掉后续转义结果
 */
function wm_like_escape(string $keyword): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword);
}

/**
 * IP 黑名单（统一解析：换行 / 中英文逗号 / 竖线均可分隔）
 */
function wm_blocked_ips(): array
{
    $out = [];
    foreach (preg_split('/[\r\n,，|]+/', (string)wm_setting('block_ips', '')) ?: [] as $ip) {
        $ip = trim($ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $out, true)) {
            $out[] = $ip;
        }
    }
    return $out;
}

/**
 * 校验图片/视频相对路径：格式白名单 + realpath 越界校验，非法返回 ''
 */
function wm_safe_media_path(string $path): string
{
    $p = ltrim(str_replace('\\', '/', $path), '/');
    if (!preg_match('#^uploads/(image|video)/\d{4}/\d{2}/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{2,5}$#', $p)) {
        return '';
    }
    $abs = realpath(WM_ROOT . '/' . $p);
    $base = realpath(WM_UPLOAD);
    if ($abs === false || $base === false || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    return $p;
}

/**
 * 校验缩略图相对路径，非法返回 ''
 */
function wm_safe_thumb_path(string $path): string
{
    $p = ltrim(str_replace('\\', '/', $path), '/');
    if ($p === '' || !preg_match('#^uploads/thumb/\d{4}/\d{2}/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{2,5}$#', $p)) {
        return '';
    }
    // 与 wm_safe_media_path 同口径：正则之外再补 realpath 越界校验，
    // 否则客户端可把 thumb 指向 uploads 内的任意同名格式文件
    $abs = realpath(WM_ROOT . '/' . $p);
    $base = realpath(WM_UPLOAD);
    if ($abs === false || $base === false || strpos($abs, $base . DIRECTORY_SEPARATOR) !== 0) {
        return '';
    }
    return $p;
}

/**
 * 敏感配置加密存储（SMTP 密码等）。无 openssl 时退化为明文，不影响可用性。
 */
function wm_secret_encode(string $plain): string
{
    if ($plain === '' || !function_exists('openssl_encrypt')) {
        return $plain;
    }
    $key = hash('sha256', defined('AUTH_SALT') ? AUTH_SALT : 'wm', true);
    $iv = random_bytes(16);
    $raw = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($raw === false) {
        return $plain;
    }
    return 'enc:' . base64_encode($iv . $raw);
}

/**
 * 读取敏感配置：无 enc: 前缀的视为历史明文，保证平滑升级
 */
function wm_secret_decode(string $stored): string
{
    if ($stored === '' || strpos($stored, 'enc:') !== 0) {
        return $stored;
    }
    if (!function_exists('openssl_decrypt')) {
        return '';
    }
    $raw = base64_decode(substr($stored, 4), true);
    if ($raw === false || strlen($raw) < 33) {
        return '';
    }
    $key = hash('sha256', defined('AUTH_SALT') ? AUTH_SALT : 'wm', true);
    $out = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    return $out === false ? '' : $out;
}

/**
 * 校验可作为头像 / 封面的上传路径（允许 image 与 thumb），非法返回 ''
 */
function wm_safe_display_path(string $path): string
{
    $p = ltrim(str_replace('\\', '/', $path), '/');
    if (!preg_match('#^uploads/(image|thumb)/\d{4}/\d{2}/[A-Za-z0-9_\-]+\.[A-Za-z0-9]{2,5}$#', $p)) {
        return '';
    }
    return $p;
}

/**
 * 校验 favicon 相对路径（仅允许 uploads/site 下的 .ico / .png），非法返回 ''
 */
function wm_safe_favicon_path(string $path): string
{
    $p = ltrim(str_replace('\\', '/', $path), '/');
    if (!preg_match('#^uploads/site/[A-Za-z0-9_\-]+\.(ico|png)$#', $p)) {
        return '';
    }
    return $p;
}

/**
 * 输出网站图标 <link>（未设置则不输出）。
 * $base 为相对站点的前缀：后台/用户中心传 '../'，前台传 ''。
 */
function wm_favicon_link(string $base = ''): void
{
    $f = wm_safe_favicon_path((string)wm_setting('favicon', ''));
    if ($f === '') {
        return;
    }
    $url = $base . $f;
    echo '<link rel="icon" type="image/x-icon" href="' . e($url) . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . e($url) . '">' . "\n";
}
