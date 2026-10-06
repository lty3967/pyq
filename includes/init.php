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

define('WM_VERSION', '1.1.2');
// 静态资源缓存戳：仅当 JS/CSS 发生改动时手动 +1，与发布版本(WM_VERSION)解耦，
// 避免「只部署了前端文件却因版本号未变导致浏览器沿用旧缓存」的问题。
define('WM_ASSET_VER', '20261006');
// 在线更新的清单地址（开发者托管更新包与 manifest.json 的位置）；
// 可在后台「在线更新」页通过 update_channel 设置覆盖，便于私有部署。
define('WM_UPDATE_CHANNEL', 'https://www.770a.cn/pyq/update/manifest.json');
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

// 表结构由安装向导一次性建全（install/sql.php），运行时不再执行任何迁移。
// 旧版本站点升级请执行根目录的 upgrade.sql。
wm_session_start();
