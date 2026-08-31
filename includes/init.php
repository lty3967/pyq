<?php
/**
 * 朋友圈系统 - 核心引导文件
 * 负责：常量定义、配置加载、数据库连接、会话安全、安全响应头
 */
declare(strict_types=1);

if (defined('WM_INIT')) {
    return;
}
define('WM_INIT', true);

define('WM_VERSION', '1.0.8');
define('WM_ROOT', dirname(__DIR__));
define('WM_INC', WM_ROOT . '/includes');
define('WM_DATA', WM_ROOT . '/data');
define('WM_UPLOAD', WM_ROOT . '/uploads');
define('WM_CONFIG_FILE', WM_INC . '/config.php');

// ---------- 基础运行环境 ----------
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', WM_DATA . '/php-error.log');
error_reporting(E_ALL);

// ---------- 安全响应头 ----------
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header_remove('X-Powered-By');
}

// ---------- 配置 ----------
if (!is_file(WM_CONFIG_FILE)) {
    if (is_dir(WM_ROOT . '/install') && !is_file(WM_DATA . '/install.lock')) {
        $self = $_SERVER['SCRIPT_NAME'] ?? '';
        if (strpos($self, '/install/') === false) {
            header('Location: ' . wm_site_root_url() . 'install/index.php');
            exit;
        }
    } else {
        http_response_code(503);
        exit('系统未安装或配置文件缺失。');
    }
} else {
    require WM_CONFIG_FILE;
}

/**
 * 站点根 URL（含末尾斜杠），基于当前脚本推导，不信任 Host 之外的输入
 */
function wm_site_root_url(): string
{
    $base = wm_base_url();
    return preg_replace('#/user/$#', '/', $base) ?: $base;
}

function wm_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    // 仅允许合法主机名字符，防 Host 头注入
    if (!preg_match('/^[A-Za-z0-9\.\-\:\[\]]{1,255}$/', $host)) {
        $host = 'localhost';
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    // 剥离 install / admin 子目录，得到站点根
    $dir = preg_replace('#/(install|admin)(/.*)?$#', '', $dir);
    $dir = rtrim((string)$dir, '/');
    $base = $scheme . '://' . $host . $dir . '/';
    return $base;
}

require WM_INC . '/functions.php';
require WM_INC . '/db.php';
require WM_INC . '/security.php';
require WM_INC . '/migrate.php';

wm_migrate();
wm_session_start();
