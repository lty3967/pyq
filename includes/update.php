<?php
/**
 * 在线更新核心逻辑
 *
 * 支持两种来源：
 *   - 远程拉取：从 manifest.json 声明的地址下载 zip 并应用（真正的「在线更新」）
 *   - 本地上传：后台管理员直接上传更新包 zip 并应用
 *
 * 应用规则（两种来源共用）：
 *   - 仅覆盖 zip 内的文件；config.php / data/ / uploads/ / install/ 受保护，绝不覆盖；
 *   - 覆盖前对已有文件做自动备份（data/backup/...），便于回滚；
 *   - 若 zip 内含 upgrade.sql，则执行其中的迁移语句；
 *   - 最后把 WM_VERSION 同步为新版本号。
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

/** 当前生效的更新通道（设置优先，其次常量） */
function wm_update_channel(): string
{
    $c = (string) wm_setting('update_channel', '');
    if ($c !== '') {
        return $c;
    }
    return defined('WM_UPDATE_CHANNEL') ? WM_UPDATE_CHANNEL : '';
}

/** HTTP GET（curl 优先，回落到 file_get_contents） */
function wm_http_get(string $url, int $timeout = 30): array
{
    $ua = 'PYQ-Updater/' . WM_VERSION;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => $ua,
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'msg' => $err !== '' ? $err : '下载失败'];
        }
        return ['ok' => true, 'data' => $body];
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'user_agent' => $ua, 'follow_location' => true, 'max_redirects' => 3],
        'https' => ['timeout' => $timeout],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['ok' => false, 'msg' => '下载失败（可能服务器禁用了 allow_url_fopen）'];
    }
    return ['ok' => true, 'data' => $body];
}

/** 拉取并解析 manifest.json */
function wm_update_fetch_manifest(string $url): array
{
    if ($url === '') {
        return ['ok' => false, 'msg' => '未配置更新通道'];
    }
    $r = wm_http_get($url, 20);
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => $r['msg']];
    }
    $data = json_decode((string) $r['data'], true);
    if (!is_array($data) || empty($data['version']) || empty($data['package'])) {
        return ['ok' => false, 'msg' => '清单格式不正确（需包含 version 与 package）'];
    }
    return ['ok' => true, 'data' => $data];
}

/** 下载更新包到本地文件 */
function wm_update_download(string $url, string $dest): array
{
    $r = wm_http_get($url, 180);
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => $r['msg']];
    }
    // 确保临时目录可写；data/tmp 不可写时回退到系统临时目录，避免「下载没反应」
    $dir = dirname($dest);
    if ((!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) || !is_writable($dir)) {
        $fallback = rtrim((string) sys_get_temp_dir(), '/') . '/pyq_update_' . wm_random(12) . '.zip';
        if (is_writable(dirname($fallback))) {
            $dest = $fallback;
            $dir = dirname($dest);
        }
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'msg' => '临时目录不可写（' . $dir . '），无法下载更新包'];
    }
    if (@file_put_contents($dest, $r['data']) === false) {
        return ['ok' => false, 'msg' => '写入更新包失败（目录不可写）'];
    }
    return ['ok' => true, 'path' => $dest, 'size' => strlen((string) $r['data'])];
}

/** 校验下载文件的哈希（manifest 的 hash 形如 sha256:hex） */
function wm_update_verify_hash(string $file, string $expect): bool
{
    if ($expect === '') {
        return true;
    }
    if (!preg_match('/^sha256:([0-9a-f]{64})$/i', $expect, $m)) {
        return false;
    }
    $real = @hash_file('sha256', $file);
    return $real !== false && strcasecmp($real, $m[1]) === 0;
}

/**
 * 把内容写入目标文件，尽量绕开「已存在文件不可写」的权限问题。
 *   1) 目录不可写时先尝试创建目录；
 *   2) 已存在但不可写时先 chmod 放开权限（PHP 属主/同组时有效）；
 *   3) 仍失败则在同一目录写临时文件再 rename 覆盖——只要「目录」可写，
 *      即使旧文件属主不是 web 进程用户也能替换成功（rename 只需目录写权限）。
 * @return bool
 */
function wm_update_write_file(string $target, string $content): bool
{
    $td = dirname($target);
    if (!is_dir($td) && !@mkdir($td, 0755, true) && !is_dir($td)) {
        return false;
    }
    if (is_file($target) && !is_writable($target)) {
        @chmod($target, 0664);
    }
    if (file_put_contents($target, $content) !== false) {
        @chmod($target, 0644);
        return true;
    }
    // 兜底：同目录临时文件 + rename（目录可写但目标文件属主不可写时仍可成功）
    $tmp = $target . '.' . wm_random(8) . '.tmp';
    if (file_put_contents($tmp, $content) !== false) {
        if (@rename($tmp, $target)) {
            @chmod($target, 0644);
            return true;
        }
        @unlink($tmp);
    }
    return false;
}

/**
 * 应用更新包（解压覆盖 + 备份 + 升级 SQL + 版本号）
 * @return array{ok:bool,msg:string,applied:int,backup:string,log:array}
 */
function wm_update_apply_zip(string $zipPath, string $newVersion): array
{
    if (!is_file($zipPath)) {
        return ['ok' => false, 'msg' => '更新包不存在', 'applied' => 0, 'backup' => '', 'log' => []];
    }
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'msg' => '服务器未启用 ZipArchive 扩展，无法解压更新包', 'applied' => 0, 'backup' => '', 'log' => []];
    }
    $za = new ZipArchive();
    if ($za->open($zipPath) !== true) {
        return ['ok' => false, 'msg' => '更新包无法打开（可能不是有效的 zip）', 'applied' => 0, 'backup' => '', 'log' => []];
    }

    $root = WM_ROOT;
    // 受保护项：绝不被更新包覆盖，避免丢配置 / 丢数据 / 重新触发安装
    $protected = ['includes/config.php', 'data/', 'uploads/', 'install/'];
    $backupDir = WM_DATA . '/backup/' . date('Ymd_His') . '_' . wm_random(6);
    $applied = 0;
    $logs = [];
    $ok = true;
    $errMsg = '';

    try {
        for ($i = 0; $i < $za->numFiles; $i++) {
            $name = $za->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $rel = ltrim(str_replace('\\', '/', (string) $name), '/');
            if ($rel === '' || $rel === '.' || $rel[0] === '/' || strpos($rel, '..') !== false) {
                continue; // 跳过危险项
            }
            // 目录项
            if (substr($rel, -1) === '/') {
                $d = $root . '/' . $rel;
                if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
                    $logs[] = '目录创建失败：' . $rel;
                }
                continue;
            }
            // 受保护项跳过
            foreach ($protected as $p) {
                if ($rel === $p || strpos($rel, $p) === 0) {
                    continue 2;
                }
            }
            $content = $za->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            $target = $root . '/' . $rel;
            // 覆盖前备份
            if (is_file($target)) {
                $bk = $backupDir . '/' . $rel;
                $bkDir = dirname($bk);
                if (!is_dir($bkDir) && !@mkdir($bkDir, 0755, true) && !is_dir($bkDir)) {
                    $logs[] = '备份目录创建失败：' . $rel;
                } else {
                    @copy($target, $bk);
                }
            }
            if (!wm_update_write_file($target, $content)) {
                $ok = false;
                $errMsg = '写入失败（权限不足或磁盘已满）：' . $rel;
                break;
            }
            $applied++;
        }

        // 升级 SQL
        $sqlEntry = $za->getFromName('upgrade.sql');
        if ($sqlEntry !== false && $sqlEntry !== '') {
            $r = wm_update_run_sql((string) $sqlEntry);
            if (!$r['ok']) {
                $ok = false;
                $errMsg = '升级脚本执行失败：' . $r['msg'];
            } else {
                $logs[] = '已执行升级脚本（' . (int) $r['done'] . ' 条）';
            }
        }
    } catch (Throwable $e) {
        $ok = false;
        $errMsg = '更新异常：' . $e->getMessage();
    }
    $za->close();

    if (!$ok) {
        return ['ok' => false, 'msg' => $errMsg, 'applied' => $applied, 'backup' => $backupDir, 'log' => $logs];
    }

    if ($newVersion !== '') {
        wm_update_bump_version($newVersion);
        $logs[] = '版本号已更新为 ' . $newVersion;
    }
    return ['ok' => true, 'msg' => '更新完成，已应用 ' . $applied . ' 个文件', 'applied' => $applied, 'backup' => $backupDir, 'log' => $logs];
}

/** 把 includes/init.php 中的 WM_VERSION 改为新版本号 */
function wm_update_bump_version(string $newVersion): bool
{
    $file = WM_INC . '/init.php';
    $c = @file_get_contents($file);
    if ($c === false) {
        return false;
    }
    $esc = str_replace(["\\", "'"], ["\\\\", "\\'"], $newVersion);
    $c = preg_replace("/define\\('WM_VERSION',\\s*'[^']*'\\)/", "define('WM_VERSION', '" . $esc . "')", $c);
    return @file_put_contents($file, $c) !== false;
}

/** 拆分并执行 SQL（按分号切分，忽略引号内分号与 -- 注释） */
function wm_update_run_sql(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $stmts = [];
    $cur = '';
    $inS = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($inS) {
            $cur .= $ch;
            if ($ch === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $cur .= "'";
                    $i++;
                } else {
                    $inS = false;
                }
            }
            continue;
        }
        if ($ch === "'") {
            $inS = true;
            $cur .= $ch;
            continue;
        }
        if ($ch === ';') {
            $stmts[] = trim($cur);
            $cur = '';
            continue;
        }
        $cur .= $ch;
    }
    if (trim($cur) !== '') {
        $stmts[] = trim($cur);
    }
    $done = 0;
    foreach ($stmts as $stmt) {
        if ($stmt === '' || strpos($stmt, '--') === 0 || strpos($stmt, '/*') === 0) {
            continue;
        }
        try {
            wm_exec($stmt);
            $done++;
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => $e->getMessage(), 'done' => $done];
        }
    }
    return ['ok' => true, 'msg' => 'ok', 'done' => $done];
}
