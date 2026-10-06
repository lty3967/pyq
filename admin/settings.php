<?php
/**
 * 站点设置
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
$admin = wm_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act');

    if ($act === 'site') {
        $siteName = wm_input('site_name');
        $siteDesc = wm_input('site_desc');
        $cover = wm_input('cover_image');
        $pageSize = wm_input_int('page_size');

        $err = '';
        if ($siteName === '' || mb_strlen($siteName) > 50) { $err = '站点名称需为 1-50 字'; }
        elseif (mb_strlen($siteDesc) > 200) { $err = '站点描述不能超过 200 字'; }
        if ($cover !== '' && wm_safe_display_path($cover) === '') {
            $cover = (string)wm_setting('cover_image', '');
        }

        if ($err !== '') {
            wm_flash(false, $err);
        } else {
            wm_setting_set('site_name', $siteName);
            wm_setting_set('site_desc', $siteDesc);
            wm_setting_set('cover_image', $cover);
            wm_setting_set('page_size', (string)max(5, min(30, $pageSize)));
            wm_setting_set('allow_like', wm_input_int('allow_like') === 1 ? '1' : '0');
            wm_setting_set('allow_comment', wm_input_int('allow_comment') === 1 ? '1' : '0');
            wm_setting_set('allow_register', wm_input_int('allow_register') === 1 ? '1' : '0');
            wm_log('修改站点设置', $siteName);
            wm_flash(true, '站点设置已保存');
        }
        wm_redirect('settings.php');
    }

    if ($act === 'upload') {
        wm_setting_set('max_image_mb', (string)max(1, min(50, wm_input_int('max_image_mb'))));
        wm_setting_set('max_video_mb', (string)max(1, min(200, wm_input_int('max_video_mb'))));
        wm_setting_set('image_max_side', (string)max(320, min(6000, wm_input_int('image_max_side'))));
        wm_setting_set('image_quality', (string)max(40, min(100, wm_input_int('image_quality'))));
        wm_setting_set('thumb_size', (string)max(80, min(1200, wm_input_int('thumb_size'))));
        wm_log('修改上传设置', '');
        wm_flash(true, '上传设置已保存');
        wm_redirect('settings.php');
    }

    if ($act === 'security') {
        wm_setting_set('login_max_fail', (string)max(3, min(20, wm_input_int('login_max_fail'))));
        wm_setting_set('login_lock_seconds', (string)max(60, min(86400, wm_input_int('login_lock_seconds'))));
        wm_setting_set('session_timeout', (string)max(300, min(86400, wm_input_int('session_timeout', 'POST', (int)wm_setting('session_timeout', '7200')))));
        wm_setting_set('user_session_timeout', (string)max(300, min(86400, wm_input_int('user_session_timeout', 'POST', (int)wm_setting('user_session_timeout', '7200')))));
        $tp = wm_input('trust_proxy');
        if (!in_array($tp, ['0', '1', '2'], true)) { $tp = '1'; }
        wm_setting_set('trust_proxy', $tp);
        wm_log('修改安全设置', '');
        wm_flash(true, '安全设置已保存');
        wm_redirect('settings.php');
    }

    if ($act === 'clean') {
        // 先回收「已入库但长期未关联动态」的孤儿记录（用户上传后放弃发布）。
        // 它们有 media 记录，下面的目录扫描会误判为「被引用」而永远跳过。
        $orphan = wm_media_purge_orphans(24, 0, 1000);

        // 清理孤立媒体文件（数据库中不存在记录的上传文件）
        $known = [];
        foreach (wm_all('SELECT path, thumb FROM ' . wm_t('media')) as $r) {
            $known[(string)$r['path']] = true;
            if ((string)$r['thumb'] !== '') { $known[(string)$r['thumb']] = true; }
        }
        foreach (['owner_avatar', 'cover_image'] as $k) {
            $v = (string)wm_setting($k, '');
            if ($v !== '') { $known[$v] = true; }
        }
        foreach (wm_all('SELECT avatar FROM ' . wm_t('admin') . " WHERE avatar <> ''") as $r) {
            $known[(string)$r['avatar']] = true;
        }
        // 前台用户头像仅存在于 user 表，不采集会被误判为孤立文件而删除
        foreach (wm_all('SELECT avatar FROM ' . wm_t('user') . " WHERE avatar <> ''") as $r) {
            $known[(string)$r['avatar']] = true;
        }

        $removed = 0;
        $freed = 0;
        $cut = time() - 3600; // 仅清理 1 小时前的文件，避免删掉正在编辑中的上传
        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(WM_UPLOAD, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if (!$f->isFile()) { continue; }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(WM_ROOT) + 1));
                if (isset($known[$rel])) { continue; }
                if ($f->getMTime() > $cut) { continue; }
                if (!preg_match('#^uploads/(image|video|thumb)/#', $rel)) { continue; }
                $sz = $f->getSize();
                if (wm_media_unlink($rel)) { $removed++; $freed += $sz; }
            }
        } catch (Throwable $e) {
            error_log('clean fail: ' . $e->getMessage());
        }
        wm_log('清理孤立文件', "孤儿媒体 {$orphan} 个，游离文件 {$removed} 个，释放 " . wm_size((float)$freed));
        // 占用已变化，让后台统计立即重算
        wm_upload_usage_reset();
        wm_flash(true, '已回收 ' . $orphan . ' 个未使用媒体、清理 ' . $removed . ' 个孤立文件，释放 ' . wm_size((float)$freed));
        wm_redirect('settings.php');
    }

    if ($act === 'clean_data') {
        $days = max(30, min(3650, wm_input_int('keep_days')));
        $n1 = wm_exec('DELETE FROM ' . wm_t('log') . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]);
        $n2 = wm_exec('DELETE FROM ' . wm_t('view') . ' WHERE day < DATE_SUB(CURDATE(), INTERVAL ? DAY)', [$days]);
        $n3 = wm_exec('DELETE FROM ' . wm_t('ratelimit') . ' WHERE expire_at < NOW()');
        wm_log('清理历史数据', "日志 {$n1}，浏览记录 {$n2}，限流 {$n3}");
        wm_flash(true, "已清理：日志 {$n1} 条、浏览记录 {$n2} 条、过期限流 {$n3} 条");
        wm_redirect('settings.php');
    }
}

$installExists = is_dir(WM_ROOT . '/install');
$cover = (string)wm_setting('cover_image', '');
// 错误日志是静态文件，PHP 无法自我保护，只能靠 Web 服务器禁止访问
$errLogFile = WM_DATA . '/php-error.log';
$errLogSize = is_file($errLogFile) ? (int)filesize($errLogFile) : 0;
// config.php 是否带 WM_INIT 守卫（代码层兜底：即便 Web 服务器漏配，直接访问也返回 403）
$confGuard = is_file(WM_CONFIG_FILE)
    && (strpos((string)@file_get_contents(WM_CONFIG_FILE), "defined('WM_INIT')") !== false
        || strpos((string)@file_get_contents(WM_CONFIG_FILE), 'defined("WM_INIT")') !== false);

wm_head('站点设置');
?>
<?php if ($installExists): ?>
  <div class="alert err"><b>安全风险：</b>安装目录 <code>install/</code> 仍然存在，请立即从服务器删除该目录，防止被重新安装覆盖。</div>
<?php endif; ?>

<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2>基本设置</h2></div>
    <form class="form" method="post" action="settings.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="site">
      <div class="fr"><label for="sn">站点名称</label><div class="fc">
        <input class="inp" type="text" id="sn" name="site_name" maxlength="50" required value="<?= e((string)wm_setting('site_name', '')) ?>">
      </div></div>
      <div class="fr"><label for="sd">站点描述</label><div class="fc">
        <textarea class="inp" id="sd" name="site_desc" rows="2" maxlength="200"><?= e((string)wm_setting('site_desc', '')) ?></textarea>
        <div class="fh">用于前台 SEO 描述</div>
      </div></div>
      <div class="fr"><label>顶部封面</label><div class="fc">
        <div data-single-upload="1" data-target="coverPath" class="frow" style="align-items:center">
          <img class="sp-img" src="<?= $cover !== '' ? '../' . e($cover) : '' ?>" alt=""
               style="width:120px;height:60px;border-radius:6px;object-fit:cover;border:1px solid #ebebeb;<?= $cover === '' ? 'display:none' : '' ?>">
          <button class="btn sm ghost sp-btn" type="button">选择图片</button>
          <button class="btn sm ghost sp-clear" type="button">清除</button>
          <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
        </div>
        <input type="hidden" name="cover_image" id="coverPath" value="<?= e($cover) ?>">
        <div class="fh">建议 1200×600 以上，未设置时使用渐变背景</div>
      </div></div>
      <div class="fr"><label for="ps">每页条数</label><div class="fc">
        <input class="inp" type="number" id="ps" name="page_size" min="5" max="30" value="<?= (int)wm_setting('page_size', '10') ?>" style="max-width:120px">
      </div></div>
      <div class="fr"><label>互动开关</label><div class="fc">
        <label class="ck"><input type="checkbox" name="allow_like" value="1" <?= (string)wm_setting('allow_like', '1') === '1' ? 'checked' : '' ?>> 允许点赞</label>
        <label class="ck"><input type="checkbox" name="allow_comment" value="1" <?= (string)wm_setting('allow_comment', '1') === '1' ? 'checked' : '' ?>> 允许评论</label>
        <label class="ck"><input type="checkbox" name="allow_register" value="1" <?= (string)wm_setting('allow_register', '1') === '1' ? 'checked' : '' ?>> 开放前台用户注册</label>
      </div></div>
      <div class="fr"><label></label><div class="fc acts"><button class="btn" type="submit">保存</button></div></div>
    </form>
  </section>

  <section class="box">
    <div class="box-hd"><h2>上传设置</h2></div>
    <form class="form" method="post" action="settings.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="upload">
      <div class="fr"><label for="mi">图片大小上限</label><div class="fc frow">
        <input class="inp" type="number" id="mi" name="max_image_mb" min="1" max="50" value="<?= (int)wm_setting('max_image_mb', '10') ?>" style="max-width:100px">
        <span style="line-height:38px;color:#909399">MB（受 PHP <?= e((string)ini_get('upload_max_filesize')) ?> 限制）</span>
      </div></div>
      <div class="fr"><label for="mv">视频大小上限</label><div class="fc frow">
        <input class="inp" type="number" id="mv" name="max_video_mb" min="1" max="200" value="<?= (int)wm_setting('max_video_mb', '40') ?>" style="max-width:100px">
        <span style="line-height:38px;color:#909399">MB</span>
      </div></div>
      <div class="fr"><label for="ms">图片最长边</label><div class="fc frow">
        <input class="inp" type="number" id="ms" name="image_max_side" min="320" max="6000" value="<?= (int)wm_setting('image_max_side', '2000') ?>" style="max-width:110px">
        <span style="line-height:38px;color:#909399">px，超出自动等比缩放</span>
      </div></div>
      <div class="fr"><label for="iq">图片质量</label><div class="fc frow">
        <input class="inp" type="number" id="iq" name="image_quality" min="40" max="100" value="<?= (int)wm_setting('image_quality', '86') ?>" style="max-width:100px">
        <input class="inp" type="number" name="thumb_size" min="80" max="1200" value="<?= (int)wm_setting('thumb_size', '400') ?>" style="max-width:120px" placeholder="缩略图边长">
        <span style="line-height:38px;color:#909399">质量 / 缩略图边长</span>
      </div></div>
      <div class="fr"><label></label><div class="fc acts"><button class="btn" type="submit">保存</button></div></div>
    </form>
  </section>
</div>

<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2>安全设置</h2></div>
    <form class="form" method="post" action="settings.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="security">
      <div class="fr"><label for="lf">登录失败上限</label><div class="fc frow">
        <input class="inp" type="number" id="lf" name="login_max_fail" min="3" max="20" value="<?= (int)wm_setting('login_max_fail', '5') ?>" style="max-width:100px">
        <span style="line-height:38px;color:#909399">次后锁定</span>
        <input class="inp" type="number" name="login_lock_seconds" min="60" max="86400" value="<?= (int)wm_setting('login_lock_seconds', '900') ?>" style="max-width:120px">
        <span style="line-height:38px;color:#909399">秒</span>
      </div></div>
      <div class="fr"><label for="st">会话超时</label><div class="fc frow">
        <input class="inp" type="number" id="st" name="session_timeout" min="300" max="86400" value="<?= (int)wm_setting('session_timeout', '7200') ?>" style="max-width:120px">
        <span style="line-height:38px;color:#909399">秒无操作自动退出（后台）</span>
        <input class="inp" type="number" name="user_session_timeout" min="300" max="86400"
               value="<?= (int)wm_setting('user_session_timeout', (string)(int)wm_setting('session_timeout', '7200')) ?>" style="max-width:120px">
        <span style="line-height:38px;color:#909399">秒（前台用户）</span>
      </div></div>
      <div class="fr"><label>反向代理</label><div class="fc">
        <?php $tp = (string)wm_setting('trust_proxy', '1'); ?>
        <select class="inp" name="trust_proxy" style="max-width:260px">
          <option value="1" <?= $tp === '1' ? 'selected' : '' ?>>智能：仅内网来源信任 XFF（推荐）</option>
          <option value="2" <?= $tp === '2' ? 'selected' : '' ?>>始终信任 XFF（CDN / 独立反代）</option>
          <option value="0" <?= $tp === '0' ? 'selected' : '' ?>>不信任：始终用直连 IP</option>
        </select>
        <div class="fh">「智能」模式下，只有请求来自内网 / 回环地址（即经过本机或内网 Nginx 反代）时才采信 X-Forwarded-For，公网直连来源会忽略该头，防止伪造 IP 绕过限流与黑名单；若站点挂在 Cloudflare 或独立反代服务器后，REMOTE_ADDR 是对方公网 IP，请选择「始终信任」。</div>
      </div></div>
      <div class="fr"><label></label><div class="fc acts"><button class="btn" type="submit">保存</button></div></div>
    </form>
  </section>

  <section class="box">
    <div class="box-hd"><h2>维护清理</h2></div>
    <form class="form" method="post" action="settings.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="clean">
      <div class="fr"><label>孤立媒体文件</label><div class="fc">
        <button class="btn ghost" type="submit" data-confirm="将回收 24 小时前上传且未关联任何动态的媒体，并删除 uploads 目录下无数据库记录、且 1 小时前上传的文件，确认继续？">扫描并清理</button>
        <div class="fh">清理上传后未使用的图片与视频，释放磁盘空间</div>
      </div></div>
    </form>
    <form class="form" method="post" action="settings.php" style="border-top:1px solid #f0f0f0;padding-top:14px">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="clean_data">
      <div class="fr"><label for="kd">历史数据保留</label><div class="fc frow">
        <input class="inp" type="number" id="kd" name="keep_days" min="30" max="3650" value="180" style="max-width:110px">
        <span style="line-height:38px;color:#909399">天</span>
        <button class="btn ghost" type="submit" data-confirm="将删除超出保留期的操作日志与浏览记录，确认继续？">清理</button>
      </div>
      <div class="fh">清理操作日志与浏览去重记录，不影响动态、评论与点赞</div>
      </div>
    </form>

    <div class="box-hd mt"><h2>安全自检</h2></div>
    <table class="kv">
      <tr><th>install 目录</th><td><?= $installExists ? '<span class="st bad">存在，应删除</span>' : '<span class="st on">已删除</span>' ?></td></tr>
      <tr><th>配置文件权限</th><td><?= is_file(WM_CONFIG_FILE) ? e(substr(sprintf('%o', fileperms(WM_CONFIG_FILE)), -4)) : '缺失' ?></td></tr>
      <tr><th>config.php 守卫</th><td><?= $confGuard
          ? '<span class="st on">已加 WM_INIT 守卫，直接访问返回 403</span>'
          : '<span class="st wait">未检测到 WM_INIT 守卫，建议重装或手动补上以防配置文件被下载</span>' ?></td></tr>
      <tr><th>错误日志</th><td><?= $errLogSize > 0
          ? '<span class="st wait">data/php-error.log 已产生 ' . e(wm_size((float)$errLogSize)) . ' 内容，含异常堆栈，务必禁止 HTTP 访问</span>'
          : '<span class="st on">暂无记录</span>' ?></td></tr>
      <tr><th>HTTPS 访问</th><td><?= (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? '<span class="st on">已启用</span>' : '<span class="st wait">当前为 HTTP</span>' ?></td></tr>
      <tr><th>PHP 错误显示</th><td><?= ini_get('display_errors') ? '<span class="st bad">已开启</span>' : '<span class="st on">已关闭</span>' ?></td></tr>
      <tr><th>数据目录可写</th><td><?= is_writable(WM_DATA) ? '<span class="st on">正常</span>' : '<span class="st bad">不可写</span>' ?></td></tr>
    </table>
  </section>
</div>
<?php wm_foot();
