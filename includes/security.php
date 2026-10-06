<?php
/**
 * 安全层：会话、CSRF、登录限流、密码、敏感词、频率限制
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

/** 安全会话启动 */
function wm_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $path = WM_DATA . '/sessions';
    if (!is_dir($path)) { @mkdir($path, 0750, true); }
    if (is_dir($path) && is_writable($path)) {
        session_save_path($path);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '7200');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('WMSID');
    session_start();

    // 绑定 UA 指纹，降低会话固定/劫持风险
    $fp = substr(hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . (defined('AUTH_SALT') ? AUTH_SALT : '')), 0, 32);
    if (!isset($_SESSION['_fp'])) {
        $_SESSION['_fp'] = $fp;
    } elseif (!hash_equals($_SESSION['_fp'], $fp)) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_fp'] = $fp;
    }
}

/** 获取 CSRF Token */
function wm_csrf_token(): string
{
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = wm_random(64);
    }
    return $_SESSION['_csrf'];
}

/** CSRF 隐藏字段 */
function wm_csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(wm_csrf_token()) . '">';
}

/** 校验 CSRF，失败则终止 */
function wm_csrf_check(bool $json = false): void
{
    $token = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sess = $_SESSION['_csrf'] ?? '';
    if (!is_string($token) || $sess === '' || !hash_equals((string)$sess, (string)$token)) {
        if ($json) {
            wm_json(false, '请求校验失败，请刷新页面后重试', [], 403);
        }
        http_response_code(403);
        exit('CSRF 校验失败，请返回刷新页面后重试。');
    }
}

/** 仅允许 POST */
function wm_require_post(bool $json = true): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        if ($json) { wm_json(false, '请求方式错误', [], 405); }
        http_response_code(405);
        exit('Method Not Allowed');
    }
}

/** 密码哈希 */
function wm_password_hash(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

/** 密码强度校验，返回错误信息或 '' */
function wm_password_weak(string $p): string
{
    if (mb_strlen($p) < 8) { return '密码长度至少 8 位'; }
    if (mb_strlen($p) > 72) { return '密码长度不能超过 72 位'; }
    $score = 0;
    if (preg_match('/[a-z]/', $p)) { $score++; }
    if (preg_match('/[A-Z]/', $p)) { $score++; }
    if (preg_match('/[0-9]/', $p)) { $score++; }
    if (preg_match('/[^A-Za-z0-9]/', $p)) { $score++; }
    if ($score < 3) { return '密码需包含大写字母、小写字母、数字、符号中至少三类'; }
    $weak = ['12345678', 'password', 'admin888', 'qwerty123', '88888888'];
    if (in_array(mb_strtolower($p), $weak, true)) { return '密码过于简单'; }
    return '';
}

/**
 * 昵称合法性校验，返回错误信息或 ''
 * 昵称会同时用于评论展示与邮件通知；允许尖括号/网址的话，
 * 该账号在前台评论时会卡在 api.php 的字符校验上而永远发不出评论。
 */
function wm_nickname_error(string $n): string
{
    if ($n === '' || mb_strlen($n) > 20) {
        return '昵称需为 1-20 字';
    }
    if (preg_match('#[<>]#u', $n) || preg_match('#https?://#i', $n)) {
        return '昵称不能包含尖括号（< >）或网址';
    }
    return '';
}

/**
 * 通用频率限制（基于数据库计数表）
 * @return bool true=允许
 */
function wm_rate_limit(string $bucket, int $max, int $window, string $subject = ''): bool
{
    $subject = $subject !== '' ? $subject : wm_ip_hash();
    $key = substr(hash('sha256', $bucket . '|' . $subject), 0, 64);
    $window = max(1, $window);
    try {
        // 概率性清理过期记录（约 1%），避免每次互动都触发一次全表 DELETE
        if (random_int(1, 100) === 1) {
            wm_exec('DELETE FROM ' . wm_t('ratelimit') . ' WHERE expire_at < NOW()');
        }
        // 只统计「尚未过期」的窗口。原实现读取时不判断 expire_at，
        // 导致计数一旦触顶就永久拒绝，只有靠 1% 概率的清理才会解锁。
        $row = wm_one('SELECT id, hits FROM ' . wm_t('ratelimit') . '
                       WHERE rkey = ? AND expire_at > NOW() LIMIT 1', [$key]);
        if ($row === null) {
            // 首次进入或窗口已过期：清掉同名残留后重新开窗
            wm_exec('DELETE FROM ' . wm_t('ratelimit') . ' WHERE rkey = ?', [$key]);
            wm_exec('INSERT INTO ' . wm_t('ratelimit') . ' (rkey, hits, expire_at) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND))
                     ON DUPLICATE KEY UPDATE hits = 1, expire_at = DATE_ADD(NOW(), INTERVAL ' . $window . ' SECOND)', [$key, $window]);
            return true;
        }
        if ((int)$row['hits'] >= $max) {
            return false;
        }
        wm_exec('UPDATE ' . wm_t('ratelimit') . ' SET hits = hits + 1 WHERE id = ?', [(int)$row['id']]);
        return true;
    } catch (Throwable $e) {
        error_log('ratelimit fail: ' . $e->getMessage());
        return true;
    }
}

/** 登录失败计数 */
function wm_login_locked(string $username): int
{
    $key = 'login|' . mb_strtolower($username) . '|' . wm_ip_hash();
    $hash = substr(hash('sha256', $key), 0, 64);
    $row = wm_one('SELECT hits, UNIX_TIMESTAMP(expire_at) AS exp FROM ' . wm_t('ratelimit') . ' WHERE rkey = ? LIMIT 1', [$hash]);
    if ($row === null) { return 0; }
    $max = (int)wm_setting('login_max_fail', '5');
    if ((int)$row['hits'] >= $max && (int)$row['exp'] > time()) {
        return (int)$row['exp'] - time();
    }
    return 0;
}

function wm_login_fail(string $username): void
{
    $key = 'login|' . mb_strtolower($username) . '|' . wm_ip_hash();
    $hash = substr(hash('sha256', $key), 0, 64);
    $window = (int)wm_setting('login_lock_seconds', '900');
    wm_exec('INSERT INTO ' . wm_t('ratelimit') . ' (rkey, hits, expire_at) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND))
             ON DUPLICATE KEY UPDATE hits = hits + 1, expire_at = DATE_ADD(NOW(), INTERVAL ' . $window . ' SECOND)', [$hash, $window]);
}

function wm_login_reset(string $username): void
{
    $key = 'login|' . mb_strtolower($username) . '|' . wm_ip_hash();
    $hash = substr(hash('sha256', $key), 0, 64);
    wm_exec('DELETE FROM ' . wm_t('ratelimit') . ' WHERE rkey = ?', [$hash]);
}

/** 敏感词列表 */
function wm_badwords(): array
{
    static $words = null;
    if ($words !== null) { return $words; }
    $raw = (string)wm_setting('bad_words', '');
    $arr = preg_split('/[\r\n,，|]+/u', $raw) ?: [];
    $words = [];
    foreach ($arr as $w) {
        $w = trim($w);
        if ($w !== '') { $words[] = $w; }
    }
    return $words;
}

/**
 * 检测敏感词，返回命中的词列表
 */
function wm_badword_hit(string $text): array
{
    // 归一化后的词表做静态缓存：否则每次调用都要重复归一化全部词条，
    // 回溯扫描（5000 条评论 × 2000 词）会直接超时
    static $normWords = null;
    if ($normWords === null) {
        $normWords = [];
        foreach (wm_badwords() as $w) {
            $wn = mb_strtolower(preg_replace('/\s+/u', '', $w) ?? $w);
            if ($wn !== '') { $normWords[$wn] = $w; }
        }
    }
    if (!$normWords) { return []; }

    $hits = [];
    $norm = mb_strtolower(preg_replace('/\s+/u', '', $text) ?? $text);
    // UTF-8 自同步，字节级 strpos 与 mb_strpos 结果一致但快得多
    foreach ($normWords as $wn => $w) {
        if (strpos($norm, (string)$wn) !== false) {
            $hits[] = $w;
        }
    }
    return $hits;
}

/** 敏感词替换为 * */
function wm_badword_mask(string $text): string
{
    foreach (wm_badwords() as $w) {
        if ($w === '') { continue; }
        $text = str_ireplace($w, str_repeat('*', mb_strlen($w)), $text);
    }
    return $text;
}

/** 校验管理员登录态 */
function wm_admin_id(): int
{
    $id = (int)($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) { return 0; }
    $timeout = (int)wm_setting('session_timeout', '7200');
    $last = (int)($_SESSION['admin_active'] ?? 0);
    // 尚无活跃基准时先落一次时间戳，避免首次请求跳过超时判断
    if ($last <= 0) {
        $_SESSION['admin_active'] = time();
        return $id;
    }
    if (time() - $last > $timeout) {
        wm_admin_logout();
        return 0;
    }
    $_SESSION['admin_active'] = time();
    return $id;
}

function wm_admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

/** 当前管理员信息 */
function wm_admin(): ?array
{
    static $admin = null;
    if ($admin !== null) { return $admin; }
    $id = wm_admin_id();
    if ($id <= 0) { return null; }
    $row = wm_one('SELECT * FROM ' . wm_t('admin') . ' WHERE id = ? AND status = 1 LIMIT 1', [$id]);
    if ($row === null) {
        wm_admin_logout();
        return null;
    }
    // 密码变更后强制失效
    if (($_SESSION['admin_pv'] ?? '') !== (string)$row['pass_version']) {
        wm_admin_logout();
        return null;
    }
    $admin = $row;
    return $admin;
}

/** 要求已登录，否则跳登录页 */
function wm_require_admin(bool $json = false): array
{
    $admin = wm_admin();
    if ($admin === null) {
        if ($json) { wm_json(false, '登录状态已失效，请重新登录', ['relogin' => true], 401); }
        wm_redirect('login.php');
    }
    return $admin;
}

/** 当前普通用户 ID */
function wm_user_id(): int
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) { return 0; }
    // 未单独配置时回退到后台「会话超时」，保证该项在后台可配置
    $timeout = (int)wm_setting('user_session_timeout', '0');
    if ($timeout <= 0) {
        $timeout = (int)wm_setting('session_timeout', '7200');
    }
    $last = (int)($_SESSION['user_active'] ?? 0);
    if ($last <= 0) {
        $_SESSION['user_active'] = time();
        return $id;
    }
    if (time() - $last > $timeout) {
        wm_user_logout();
        return 0;
    }
    $_SESSION['user_active'] = time();
    return $id;
}

function wm_user(): ?array
{
    static $user = null;
    if ($user !== null) { return $user; }
    $id = wm_user_id();
    if ($id <= 0) { return null; }
    $row = wm_one('SELECT * FROM ' . wm_t('user') . ' WHERE id = ? AND status = 1 LIMIT 1', [$id]);
    if ($row === null || (string)($row['pass_version'] ?? '') !== (string)($_SESSION['user_pv'] ?? '')) {
        wm_user_logout();
        return null;
    }
    $user = $row;
    return $user;
}

function wm_user_logout(): void
{
    unset($_SESSION['user_id'], $_SESSION['user_pv'], $_SESSION['user_active']);
}

function wm_require_user(bool $json = false): array
{
    $user = wm_user();
    if ($user === null) {
        if ($json) { wm_json(false, '登录状态已失效，请重新登录', ['relogin' => true], 401); }
        wm_redirect('login.php');
    }
    return $user;
}
