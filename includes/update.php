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
/**
 * 新增文件的默认权限
 *
 * 更新包里新增的文件（本次的 music.php / emoji.js / music.js 等）没有「原权限」
 * 可继承，若统一给 0644，会和站点里其它 755 的文件不一致。这里取同目录已有
 * 文件的权限（优先带执行位的常见权限），取不到再用 0755。
 */
function wm_update_default_file_mode(string $dir): int
{
    $sample = @scandir($dir);
    if (is_array($sample)) {
        foreach ($sample as $name) {
            if ($name === '.' || $name === '..' || $name === '' ) {
                continue;
            }
            $p = $dir . '/' . $name;
            if (!is_file($p)) {
                continue;
            }
            $m = fileperms($p) & 0777;
            if ($m !== 0 && ($m & 0111) !== 0) {
                return $m;
            }
        }
    }
    return 0755;
}

function wm_update_write_file(string $target, string $content): bool
{
    $td = dirname($target);
    if (!is_dir($td) && !@mkdir($td, 0755, true) && !is_dir($td)) {
        return false;
    }

    // 覆盖前先记住原权限。
    // 早期实现写入后直接 chmod 0644，会把站点原有的 755 全部降级，
    // 用户表现为「更新一次，全站文件权限都被改掉了」。
    $ownMode = is_file($target) ? (fileperms($target) & 0777) : 0;
    $dirMode = wm_update_default_file_mode($td);
    if ($ownMode === 0) {
        // 新增文件：跟随同目录惯例
        $mode = $dirMode;
    } elseif ($ownMode === 0644 && ($dirMode & 0111) !== 0) {
        // 恰好是上一版更新器压平后的 0644，顺手还原成站点惯例，
        // 这样升级到本版即可自愈，无需手工改权限
        $mode = $dirMode;
    } else {
        $mode = $ownMode;
    }

    if (is_file($target) && !is_writable($target)) {
        // 只补属主写权限，不动其他位
        @chmod($target, $mode | 0200);
    }

    $ok = @file_put_contents($target, $content) !== false;
    if (!$ok) {
        // 兜底：同目录临时文件 + rename（目录可写但目标文件属主不可写时仍可成功）
        $tmp = $target . '.' . wm_random(8) . '.tmp';
        if (@file_put_contents($tmp, $content) !== false) {
            if (@rename($tmp, $target)) {
                $ok = true;
            } else {
                @unlink($tmp);
            }
        }
    }
    if (!$ok) {
        return false;
    }

    // 还原权限：覆盖写的沿用原权限，新增文件沿用同目录惯例
    @chmod($target, $mode);
    return true;
}

/**
 * 修复站点代码文件权限
 *
 * 早期版本的更新器在写完文件后统一 chmod 0644，会把站点原有的 755 降级。
 * 这里把代码文件统一还原为站点主流权限（通常是 755），并跳过配置与数据目录。
 *
 * @return array{ok:bool,msg:string,fixed:int,skipped:int,mode:int}
 */
function wm_update_fix_permissions(?string $root = null): array
{
    $root = $root !== null ? rtrim($root, '/') : WM_ROOT;
    if (!is_dir($root)) {
        return ['ok' => false, 'msg' => '站点目录不存在', 'fixed' => 0, 'skipped' => 0, 'mode' => 0];
    }

    // 目标权限取「站点根目录下 php 文件里出现最多的那个」，取不到用 0755
    $counter = [];
    foreach ((array)@scandir($root) as $name) {
        if (!is_string($name) || $name === '.' || $name === '..') {
            continue;
        }
        $p = $root . '/' . $name;
        if (is_file($p) && strtolower((string)pathinfo($p, PATHINFO_EXTENSION)) === 'php') {
            $m = fileperms($p) & 0777;
            if ($m !== 0) {
                $counter[$m] = ($counter[$m] ?? 0) + 1;
            }
        }
    }
    arsort($counter);
    $mode = 0;
    foreach ($counter as $m => $n) {
        $mode = (int)$m;
        break;
    }
    if ($mode === 0) {
        $mode = 0755;
    }

    // 绝不碰配置与数据：改错权限会导致站点起不来或数据被暴露
    $skipDirs = ['data', 'uploads', 'install', '.git', '.idea', 'node_modules'];
    $exts = ['php', 'js', 'css', 'html', 'htm', 'svg', 'json', 'txt', 'md'];
    $fixed = 0;
    $skipped = 0;

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        /** @var SplFileInfo $item */
        $path = $item->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
        if ($rel === '') {
            continue;
        }
        $top = strtok($rel, '/');
        if (in_array((string)$top, $skipDirs, true)) {
            $skipped++;
            continue;
        }
        if (in_array(strtolower($item->getExtension()), $exts, true) && is_file($path)) {
            if ((fileperms($path) & 0777) !== $mode && @chmod($path, $mode)) {
                $fixed++;
            }
        }
    }

    return [
        'ok' => true,
        'msg' => '已修复 ' . $fixed . ' 个文件权限为 ' . substr(sprintf('%o', $mode), -4)
            . ($skipped > 0 ? '，跳过受保护目录 ' . $skipped . ' 项' : ''),
        'fixed' => $fixed,
        'skipped' => $skipped,
        'mode' => $mode,
    ];
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
            // 写完立刻校验内容是否与包内一致。
            // 「以为更新成功了、其实文件没被真正覆盖」的情况非常难查：
            // 页面能开、功能却是旧行为，只能靠运行时异常去发现。
            $written = @file_get_contents($target);
            if ($written === false || md5($written) !== md5($content)) {
                $ok = false;
                $errMsg = '写入后校验不一致（文件未真正覆盖，可能被 OPcache 缓存或权限受限）：' . $rel;
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
    // 服务器常开 OPcache，且部分面板把 opcache.validate_timestamps 关掉了，
    // 这时 PHP 仍会执行磁盘上的旧字节码——必须重启 PHP 才能生效
    if (function_exists('opcache_get_status') && ini_get('opcache.enable')) {
        $st = @opcache_get_status(false);
        if (is_array($st) && empty($st['directives']['opcache.validate_timestamps'])) {
            $logs[] = '检测到 OPcache 已关闭时间戳校验，请重启 PHP 服务让新代码生效';
        } else {
            $logs[] = '如仍行为异常，请重启 PHP 服务以清空 OPcache';
        }
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

/**
 * 把 SQL 脚本切成一条条语句
 *
 * 必须先剥离注释再按分号切分：注释行与其后的 SQL 在文本上是连在一起的，
 * 若只按分号切分再「跳过以 -- 开头的片段」，注释后面的语句会被连带一起跳过
 * （曾导致 ADD COLUMN 被吞、只剩 ADD KEY 执行，报 1072 字段不存在）。
 *
 * 同时识别：'...' / "..." / `...` 包裹的内容（含 '' 双写与反斜杠转义）、
 * -- 行注释（后面须跟空白）、# 行注释，以及 C 风格的块注释。
 *
 * @return string[]
 */
function wm_update_sql_statements(string $sql): array
{
    $sql = str_replace(["\r\n", "\r"], "\n", $sql);
    $out = [];
    $cur = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];

        // 块注释：整段丢弃（含 MySQL/MariaDB 的可执行注释 /*! ... */）
        if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            $cur .= ' ';
            continue;
        }

        // 行注释 --：MySQL 要求 -- 后必须是空白或行尾，否则视为减号
        if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-'
            && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n")) {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $len : $end;
            $cur .= ' ';
            continue;
        }

        // 行注释 #：仅在行首或前面是空白时才算注释，避免误伤 a#b
        if ($ch === '#') {
            $prev = $cur === '' ? "\n" : substr($cur, -1);
            if ($prev === "\n" || $prev === ' ' || $prev === "\t") {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $cur .= ' ';
                continue;
            }
        }

        // 引号 / 反引号包裹的内容原样保留，里面的分号不是语句边界
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $q = $ch;
            $cur .= $ch;
            $i++;
            while ($i < $len) {
                $c = $sql[$i];
                // 反斜杠转义（反引号内不转义）
                if ($c === '\\' && $q !== '`' && $i + 1 < $len) {
                    $cur .= $c . $sql[$i + 1];
                    $i += 2;
                    continue;
                }
                $cur .= $c;
                $i++;
                if ($c !== $q) {
                    continue;
                }
                // 连续两个引号 = 引号本身
                if ($i < $len && $sql[$i] === $q) {
                    $cur .= $q;
                    $i++;
                    continue;
                }
                break;
            }
            continue;
        }

        if ($ch === ';') {
            $t = trim($cur);
            if ($t !== '') { $out[] = $t; }
            $cur = '';
            $i++;
            continue;
        }

        $cur .= $ch;
        $i++;
    }

    $t = trim($cur);
    if ($t !== '') { $out[] = $t; }

    return $out;
}

/** 拆分并执行 SQL 脚本 */
function wm_update_run_sql(string $sql): array
{
    $stmts = wm_update_sql_statements($sql);
    $done = 0;
    foreach ($stmts as $stmt) {
        if ($stmt === '' || strpos($stmt, '--') === 0 || strpos($stmt, '/*') === 0) {
            continue;
        }
        try {
            wm_exec($stmt);
            $done++;
        } catch (Throwable $e) {
            // 「已存在」类错误视为成功：update.sql 允许重复执行，
            // 重复导入时不应用整包更新判定为失败。
            if (wm_update_sql_idempotent($e)) {
                $done++;
                continue;
            }
            return ['ok' => false, 'msg' => $e->getMessage(), 'done' => $done];
        }
    }
    return ['ok' => true, 'msg' => 'ok', 'done' => $done];
}

/**
 * 判断 SQL 异常是否属于「对象已存在」这类可忽略错误
 * 1050 表已存在、1051/1052 索引已存在、1060 字段已存在、1061 键名已存在、
 * 1062 唯一键冲突（补插入默认值时的正常情况）、1826 重复外键。
 */
function wm_update_sql_idempotent(Throwable $e): bool
{
    $code = 0;
    if ($e instanceof PDOException) {
        $info = $e->errorInfo ?? [];
        $code = isset($info[1]) ? (int)$info[1] : 0;
    }
    if ($code === 0) {
        $msg = $e->getMessage();
        foreach (['Duplicate column', 'Duplicate key name', 'already exists', 'Duplicate entry'] as $needle) {
            if (stripos($msg, $needle) !== false) {
                return true;
            }
        }
    }
    return in_array($code, [1050, 1051, 1052, 1060, 1061, 1062, 1826], true);
}
