<?php
/**
 * 数据库层：PDO 单例 + 参数化查询封装
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

// 数据库连接常量由 includes/config.php（安装程序生成）定义。
// 静态分析（intelephense）看不到该文件，且手工部署时也可能漏项；
// 访问未定义常量在 PHP 8 下会抛 Error、PHP 7.4 下会退化为同名字符串，
// 这里按「先检查后定义」补齐兜底值，缺失检测改由 wm_db() 统一做。
if (!defined('DB_HOST'))   { define('DB_HOST', '127.0.0.1'); }
if (!defined('DB_PORT'))   { define('DB_PORT', 3306); }
if (!defined('DB_NAME'))   { define('DB_NAME', ''); }
if (!defined('DB_USER'))   { define('DB_USER', ''); }
if (!defined('DB_PASS'))   { define('DB_PASS', ''); }
if (!defined('DB_PREFIX')) { define('DB_PREFIX', 'wm_'); }

function wm_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    // 只判断 DB_HOST 是否已定义不够：config.php 存在但被手工改坏
    // （缺 DB_USER / DB_PASS / DB_NAME）时同样连不上。这里改为校验必填值。
    if (DB_NAME === '' || DB_USER === '') {
        http_response_code(503);
        exit('数据库配置缺失');
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, (int)DB_PORT, DB_NAME);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    } catch (PDOException $e) {
        error_log('DB connect fail: ' . $e->getMessage());
        http_response_code(500);
        exit('数据库连接失败，请检查配置。');
    }
    return $pdo;
}

/** 执行语句，返回 PDOStatement */
function wm_query(string $sql, array $params = []): PDOStatement
{
    $st = wm_db()->prepare($sql);
    foreach ($params as $k => $v) {
        $key = is_int($k) ? $k + 1 : $k;
        $type = PDO::PARAM_STR;
        if (is_int($v)) { $type = PDO::PARAM_INT; }
        elseif (is_bool($v)) { $type = PDO::PARAM_BOOL; }
        elseif ($v === null) { $type = PDO::PARAM_NULL; }
        $st->bindValue($key, $v, $type);
    }
    $st->execute();
    return $st;
}

function wm_one(string $sql, array $params = []): ?array
{
    $row = wm_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function wm_all(string $sql, array $params = []): array
{
    return wm_query($sql, $params)->fetchAll();
}

function wm_value(string $sql, array $params = [])
{
    $v = wm_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function wm_exec(string $sql, array $params = []): int
{
    return wm_query($sql, $params)->rowCount();
}

function wm_insert_id(): int
{
    return (int)wm_db()->lastInsertId();
}

/** 表名前缀 */
function wm_t(string $name): string
{
    $prefix = defined('DB_PREFIX') ? DB_PREFIX : 'wm_';
    return $prefix . $name;
}

/** 设置缓存容器（引用返回，便于写入时同步） */
function &wm_setting_store(): array
{
    static $cache = [];
    return $cache;
}

/** 首次访问时把设置表整体载入缓存 */
function wm_setting_load(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    $cache = &wm_setting_store();
    try {
        foreach (wm_all('SELECT skey, svalue FROM ' . wm_t('setting')) as $r) {
            $cache[$r['skey']] = $r['svalue'];
        }
    } catch (Throwable $e) {
        error_log('setting load fail: ' . $e->getMessage());
    }
}

/** 读取设置项（带缓存） */
function wm_setting(string $key, $default = null)
{
    wm_setting_load();
    $cache = wm_setting_store();
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** 写入设置项（同时同步进程内缓存，避免同请求内先写后读拿到旧值） */
function wm_setting_set(string $key, string $value): void
{
    wm_exec('INSERT INTO ' . wm_t('setting') . ' (skey, svalue) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)', [$key, $value]);
    wm_setting_load();
    $cache = &wm_setting_store();
    $cache[$key] = $value;
}
