<?php
/**
 * 安装向导
 */
declare(strict_types=1);
define('WM_INSTALL', true);
define('WM_INIT', true);
define('WM_ROOT', dirname(__DIR__));
define('WM_INC', WM_ROOT . '/includes');
define('WM_DATA', WM_ROOT . '/data');
define('WM_UPLOAD', WM_ROOT . '/uploads');
define('WM_CONFIG_FILE', WM_INC . '/config.php');
define('WM_LOCK_FILE', WM_DATA . '/install.lock');
// 安装页不加载 init.php（需自行定义常量），此处单独声明以便展示与程序一致的版本号
define('WM_VERSION', '1.3.3');

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');
ini_set('display_errors', '0');
error_reporting(E_ALL);
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
}

if (!is_dir(WM_DATA)) { @mkdir(WM_DATA, 0750, true); }

// 已存在配置文件时同样拒绝安装：仅靠 install.lock 保护，一旦 lock 被删
// 且 install 目录未清理，攻击者即可重装并覆盖管理员账号
if (is_file(WM_LOCK_FILE) || is_file(WM_CONFIG_FILE)) {
    http_response_code(403);
    exit('<meta charset="utf-8"><div style="font:15px/1.8 sans-serif;padding:40px;text-align:center">系统已安装完成。<br>如需重新安装，请先删除 <code>includes/config.php</code> 与 <code>data/install.lock</code>。<br><a href="../index.php">进入首页</a></div>');
}

require WM_INC . '/functions.php';
require __DIR__ . '/sql.php';

session_name('WMINST');
// 与主程序同口径：仅 Cookie 传输 + HttpOnly + SameSite + 严格模式，防会话固定
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}
function inst_csrf(): string { return (string)$_SESSION['_csrf']; }
function inst_csrf_check(): void
{
    $t = $_POST['_token'] ?? '';
    if (!is_string($t) || !hash_equals(inst_csrf(), $t)) {
        exit('<meta charset="utf-8">安装令牌校验失败，请返回重试。');
    }
}

$step = max(1, min(4, (int)($_GET['step'] ?? 1)));
$errors = [];
$done = [];

// ---------- 环境检测 ----------
function inst_env_checks(): array
{
    $c = [];
    $c[] = ['PHP 版本 ≥ 7.4', PHP_VERSION_ID >= 70400, PHP_VERSION];
    foreach (['pdo_mysql' => 'PDO MySQL', 'gd' => 'GD 图形库', 'mbstring' => 'mbstring', 'json' => 'JSON', 'fileinfo' => 'Fileinfo', 'openssl' => 'OpenSSL'] as $ext => $label) {
        $c[] = [$label . ' 扩展', extension_loaded($ext), extension_loaded($ext) ? '已安装' : '未安装'];
    }
    $c[] = ['session 可用', function_exists('session_start'), 'ok'];
    return $c;
}

function inst_dir_checks(): array
{
    $dirs = ['includes', 'data', 'uploads', 'uploads/image', 'uploads/video', 'uploads/thumb'];
    $out = [];
    foreach ($dirs as $d) {
        $p = WM_ROOT . '/' . $d;
        if (!is_dir($p)) { @mkdir($p, 0755, true); }
        $out[] = [$d . '/', is_dir($p) && is_writable($p), is_dir($p) ? (is_writable($p) ? '可写' : '不可写') : '不存在'];
    }
    return $out;
}

// ---------- 步骤处理 ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    inst_csrf_check();
    $act = $_POST['act'] ?? '';

    if ($act === 'db') {
        $host = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
        $port = (int)($_POST['db_port'] ?? 3306);
        $name = trim((string)($_POST['db_name'] ?? ''));
        $user = trim((string)($_POST['db_user'] ?? ''));
        $pass = (string)($_POST['db_pass'] ?? '');
        $prefix = trim((string)($_POST['db_prefix'] ?? 'wm_'));

        if ($host === '') { $errors[] = '数据库地址不能为空'; }
        if ($port < 1 || $port > 65535) { $errors[] = '数据库端口无效'; }
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name)) { $errors[] = '数据库名格式无效'; }
        if ($user === '' || mb_strlen($user) > 64) { $errors[] = '数据库用户名无效'; }
        if (!preg_match('/^[A-Za-z0-9_]{0,20}$/', $prefix)) { $errors[] = '表前缀只能是字母数字下划线'; }

        if (!$errors) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $_SESSION['inst_db'] = compact('host', 'port', 'name', 'user', 'pass', 'prefix');
                header('Location: index.php?step=3');
                exit;
            } catch (PDOException $e) {
                $errors[] = '数据库连接失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }
        $step = 2;
    } elseif ($act === 'admin') {
        $db = $_SESSION['inst_db'] ?? null;
        if (!is_array($db)) {
            header('Location: index.php?step=2');
            exit;
        }
        $siteName = trim((string)($_POST['site_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $password2 = (string)($_POST['password2'] ?? '');
        $email = trim((string)($_POST['email'] ?? ''));

        if ($siteName === '' || mb_strlen($siteName) > 50) { $errors[] = '站点名称长度需为 1-50 字'; }
        if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $username)) { $errors[] = '管理员账号需为 3-20 位字母、数字或下划线'; }
        if ($password !== $password2) { $errors[] = '两次输入的密码不一致'; }
        if (mb_strlen($password) < 8) { $errors[] = '密码长度至少 8 位'; }
        $sc = 0;
        foreach (['/[a-z]/', '/[A-Z]/', '/[0-9]/', '/[^A-Za-z0-9]/'] as $re) { if (preg_match($re, $password)) { $sc++; } }
        if ($sc < 3) { $errors[] = '密码需包含大写字母、小写字母、数字、符号中至少三类'; }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = '邮箱格式无效'; }

        if (!$errors) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db['host'], (int)$db['port'], $db['name']);
                $pdo = new PDO($dsn, $db['user'], $db['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $p = $db['prefix'];
                foreach (wm_schema($p) as $sql) {
                    $pdo->exec($sql);
                }
                // 可选增强：点赞去重键升级为「账号 / IP」双口径（低版本 MySQL 自动跳过）
                wm_schema_enhance($pdo, $p);
                // 默认设置
                $settings = wm_default_settings();
                $settings['site_name'] = $siteName;
                $settings['owner_name'] = $username;
                $settings['smtp_from_name'] = $siteName;
                if ($email !== '') { $settings['notify_email'] = $email; }
                $st = $pdo->prepare("INSERT INTO `{$p}setting` (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)");
                foreach ($settings as $k => $v) {
                    $st->execute([$k, (string)$v]);
                }
                // 管理员
                $exists = $pdo->prepare("SELECT id FROM `{$p}admin` WHERE username = ? LIMIT 1");
                $exists->execute([$username]);
                if ($exists->fetchColumn() === false) {
                    $ins = $pdo->prepare("INSERT INTO `{$p}admin` (username, password, nickname, email, role, status, pass_version, created_at)
                                          VALUES (?, ?, ?, ?, 'super', 1, 1, NOW())");
                    $ins->execute([$username, password_hash($password, PASSWORD_DEFAULT), $username, $email]);
                }
                // 默认分类
                $cats = [['生活', 'life', '#07c160', 1], ['旅行', 'travel', '#1989fa', 2], ['美食', 'food', '#ff976a', 3], ['随想', 'thought', '#7232dd', 4]];
                $ci = $pdo->prepare("INSERT IGNORE INTO `{$p}category` (name, slug, color, sort, status) VALUES (?, ?, ?, ?, 1)");
                foreach ($cats as $c) { $ci->execute($c); }

                // 写配置文件
                $salt = bin2hex(random_bytes(32));
                $conf = "<?php\n"
                    . "// 自动生成于 " . date('Y-m-d H:i:s') . "，请勿泄露\n"
                    . "if (!defined('WM_INIT')) { http_response_code(403); exit('403'); }\n"
                    . "define('DB_HOST', " . var_export($db['host'], true) . ");\n"
                    . "define('DB_PORT', " . var_export((int)$db['port'], true) . ");\n"
                    . "define('DB_NAME', " . var_export($db['name'], true) . ");\n"
                    . "define('DB_USER', " . var_export($db['user'], true) . ");\n"
                    . "define('DB_PASS', " . var_export($db['pass'], true) . ");\n"
                    . "define('DB_PREFIX', " . var_export($db['prefix'], true) . ");\n"
                    . "define('AUTH_SALT', " . var_export($salt, true) . ");\n";
                if (@file_put_contents(WM_CONFIG_FILE, $conf, LOCK_EX) === false) {
                    $errors[] = '配置文件写入失败，请检查 includes/ 目录权限';
                } else {
                    @chmod(WM_CONFIG_FILE, 0640);
                    @file_put_contents(WM_LOCK_FILE, date('Y-m-d H:i:s') . " installed\n", LOCK_EX);
                    @chmod(WM_LOCK_FILE, 0640);
                    // 防目录列出与直接访问（data/index.php 已随源码存在时无需重复写）
                    if (!is_file(WM_DATA . '/index.php')) {
                        @file_put_contents(WM_DATA . '/index.html', '');
                    }
                    $_SESSION['inst_done_user'] = $username;
                    unset($_SESSION['inst_db']);
                    header('Location: index.php?step=4');
                    exit;
                }
            } catch (Throwable $e) {
                $errors[] = '安装失败：' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }
        $step = 3;
    }
}

$envOk = true;
if ($step === 1) {
    foreach (array_merge(inst_env_checks(), inst_dir_checks()) as $c) {
        if (!$c[1]) { $envOk = false; }
    }
}
$prefill = $_SESSION['inst_db'] ?? ['host' => '127.0.0.1', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '', 'prefix' => 'wm_'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>安装向导 - 朋友圈系统</title>
<link rel="stylesheet" href="../assets/css/install.css?v=1">
</head>
<body>
<div class="wrap">
  <header class="hd">
    <div class="logo">朋友圈系统 安装向导</div>
    <div class="ver">v<?= e(WM_VERSION) ?></div>
  </header>
  <ol class="steps">
    <?php foreach ([1 => '环境检测', 2 => '数据库配置', 3 => '创建管理员', 4 => '安装完成'] as $i => $label): ?>
      <li class="<?= $i < $step ? 'ok' : ($i === $step ? 'cur' : '') ?>"><span class="n"><?= $i ?></span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></li>
    <?php endforeach; ?>
  </ol>

  <?php if ($errors): ?>
    <div class="alert err">
      <?php foreach ($errors as $er): ?><p><?= $er ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <main class="box">
  <?php if ($step === 1): ?>
    <h2>运行环境检测</h2>
    <table class="chk">
      <tbody>
      <?php foreach (inst_env_checks() as $c): ?>
        <tr><td><?= htmlspecialchars((string)$c[0], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="v"><?= htmlspecialchars((string)$c[2], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="s <?= $c[1] ? 'y' : 'n' ?>"><?= $c[1] ? '通过' : '不通过' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <h2>目录权限检测</h2>
    <table class="chk">
      <tbody>
      <?php foreach (inst_dir_checks() as $c): ?>
        <tr><td><?= htmlspecialchars((string)$c[0], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="v"><?= htmlspecialchars((string)$c[2], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="s <?= $c[1] ? 'y' : 'n' ?>"><?= $c[1] ? '通过' : '不通过' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="act">
      <?php if ($envOk): ?>
        <a class="btn" href="index.php?step=2">下一步：配置数据库</a>
      <?php else: ?>
        <span class="btn disabled">请先修复不通过的检测项</span>
        <a class="btn ghost" href="index.php?step=1">重新检测</a>
      <?php endif; ?>
    </div>

  <?php elseif ($step === 2): ?>
    <h2>数据库配置</h2>
    <p class="tip">请在宝塔面板中先创建数据库，然后填写以下信息。</p>
    <form method="post" action="index.php?step=2" autocomplete="off">
      <input type="hidden" name="_token" value="<?= htmlspecialchars(inst_csrf(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="act" value="db">
      <div class="f"><label>数据库地址</label><input type="text" name="db_host" value="<?= htmlspecialchars((string)$prefill['host'], ENT_QUOTES, 'UTF-8') ?>" required></div>
      <div class="f"><label>端口</label><input type="number" name="db_port" value="<?= (int)$prefill['port'] ?>" min="1" max="65535" required></div>
      <div class="f"><label>数据库名</label><input type="text" name="db_name" value="<?= htmlspecialchars((string)$prefill['name'], ENT_QUOTES, 'UTF-8') ?>" required></div>
      <div class="f"><label>用户名</label><input type="text" name="db_user" value="<?= htmlspecialchars((string)$prefill['user'], ENT_QUOTES, 'UTF-8') ?>" required></div>
      <div class="f"><label>密码</label><input type="password" name="db_pass" value="" autocomplete="new-password"></div>
      <div class="f"><label>表前缀</label><input type="text" name="db_prefix" value="<?= htmlspecialchars((string)$prefill['prefix'], ENT_QUOTES, 'UTF-8') ?>" required></div>
      <div class="act"><a class="btn ghost" href="index.php?step=1">上一步</a><button class="btn" type="submit">测试并继续</button></div>
    </form>

  <?php elseif ($step === 3): ?>
    <h2>创建管理员</h2>
    <form method="post" action="index.php?step=3" autocomplete="off">
      <input type="hidden" name="_token" value="<?= htmlspecialchars(inst_csrf(), ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="act" value="admin">
      <div class="f"><label>站点名称</label><input type="text" name="site_name" value="<?= htmlspecialchars((string)($_POST['site_name'] ?? '我的朋友圈'), ENT_QUOTES, 'UTF-8') ?>" maxlength="50" required></div>
      <div class="f"><label>管理员账号</label><input type="text" name="username" value="<?= htmlspecialchars((string)($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" pattern="[A-Za-z0-9_]{3,20}" required></div>
      <div class="f"><label>登录密码</label><input type="password" name="password" autocomplete="new-password" required></div>
      <div class="f"><label>确认密码</label><input type="password" name="password2" autocomplete="new-password" required></div>
      <div class="f"><label>邮箱（选填）</label><input type="email" name="email" value="<?= htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
      <p class="tip">密码至少 8 位，且包含大小写字母、数字、符号中的至少三类。</p>
      <div class="act"><a class="btn ghost" href="index.php?step=2">上一步</a><button class="btn" type="submit">开始安装</button></div>
    </form>

  <?php else: ?>
    <h2>安装完成</h2>
    <div class="alert ok">
      <p>系统已成功安装，管理员账号：<strong><?= htmlspecialchars((string)($_SESSION['inst_done_user'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong></p>
    </div>
    <ul class="next">
      <li>为了安全，请立即<strong>删除服务器上的 install 目录</strong>。</li>
      <li>配置文件已写入 <code>includes/config.php</code>，请勿对外泄露。</li>
      <li><b>禁止外部访问 <code>data/</code> 与 <code>includes/</code> 目录</b>：Apache 靠自带 <code>.htaccess</code> 自动生效；Nginx 不读 .htaccess，可在站点配置中禁止访问（宝塔「禁止访问目录」或「配置文件」粘贴规则，不强制单独 nginx.conf 文件），或把这两个目录移出网站根目录；<code>config.php</code> 已内置 WM_INIT 守卫，即使被直接请求也返回 403，可作兜底。</li>
    </ul>
    <div class="act">
      <a class="btn" href="../index.php">访问前台</a>
      <a class="btn ghost" href="../admin/login.php">进入后台</a>
    </div>
  <?php endif; ?>
  </main>
  <footer class="ft">安装完成后请务必删除 install 目录</footer>
</div>
</body>
</html>
