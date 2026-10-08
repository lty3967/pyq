<?php
/**
 * 聚合登录（QQ / 微信快捷登录）设置
 *
 * 本站不直接对接 QQ / 微信开放平台，而是对接第三方「聚合登录」服务商接口。
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
require_once WM_INC . '/oauth.php';
$admin = wm_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act');

    if ($act === 'save') {
        $on     = wm_input_int('oauth_on') === 1;
        $api    = wm_input('oauth_api');
        $appid  = wm_input('oauth_appid');
        $appkey = (string)($_POST['oauth_appkey'] ?? '');
        $auto   = wm_input_int('oauth_auto_register') === 1;

        $methods = [];
        foreach (array_keys(wm_oauth_methods()) as $k) {
            if (wm_input_int('method_' . $k) === 1) {
                $methods[] = $k;
            }
        }

        $err = '';
        if ($api !== '' && wm_oauth_normalize_endpoint($api) === '') {
            $err = '接口地址格式不正确，请填写站点根地址或完整接口地址，例如 https://u.770b.cn/';
        } elseif (mb_strlen($api) > 500 || mb_strlen($appid) > 100) {
            $err = '接口地址或 APPID 过长';
        } elseif ($on && ($api === '' || $appid === '')) {
            $err = '开启聚合登录前必须填写接口地址与应用 APPID';
        } elseif ($on && $appkey === '' && (string)wm_setting('oauth_appkey', '') === '') {
            $err = '开启聚合登录前必须填写应用 APPKEY';
        } elseif ($on && !$methods) {
            $err = '开启聚合登录后至少要勾选一种登录方式';
        }
        // 回调地址必须是完整可访问的 http(s)
        if ($err === '' && $on && !preg_match('#^https?://#i', wm_oauth_callback_url())) {
            $err = '本站回调地址不是合法的 http(s) 地址，请检查站点访问域名';
        }

        if ($err !== '') {
            wm_flash(false, $err);
        } else {
            wm_setting_set('oauth_on', $on ? '1' : '0');
            wm_setting_set('oauth_api', $api);
            wm_setting_set('oauth_appid', $appid);
            // APPKEY 留空表示不修改，避免每次保存都要重填
            if ($appkey !== '') {
                wm_setting_set('oauth_appkey', wm_secret_encode($appkey));
            }
            wm_setting_set('oauth_methods', implode(',', $methods));
            wm_setting_set('oauth_auto_register', $auto ? '1' : '0');
            wm_log('修改聚合登录设置', $on ? ('已开启：' . implode('/', $methods)) : '已关闭');
            wm_flash(true, '聚合登录设置已保存');
        }
        wm_redirect('oauth.php');
    }

    if ($act === 'test') {
        $r = wm_oauth_probe();
        wm_log('测试聚合登录接口', $r['ok'] ? '可访问' : (string)$r['msg']);
        wm_flash((bool)$r['ok'], (string)$r['msg']);
        wm_redirect('oauth.php');
    }
}

$cfg = wm_oauth_cfg();
$defs = wm_oauth_methods();
$ready = wm_oauth_ready();
$boundCount = 0;
if ($ready) {
    try {
        $boundCount = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('user_oauth'));
    } catch (Throwable $e) {
        $boundCount = 0;
    }
}
// APPKEY 已设置但无法解密（AUTH_SALT 变更）时提示重填
$appkeyBroken = (string)wm_setting('oauth_appkey', '') !== ''
    && wm_secret_decode((string)wm_setting('oauth_appkey', '')) === '';

// 展示用文案：把配置里的方式键翻译成「QQ / 微信」。
// 提前算好，避免在短标签输出里写 implode(array_map(闭包)) 这种三层嵌套，
// 既容易漏右括号，也让模板可读性变差。
// 注意：此处注释切勿出现 PHP 的短标签闭合标记，单行注释遇到它会结束注释并切回 HTML 模式。
$methodNames = [];
foreach ($cfg['methods'] as $mk) {
    $methodNames[] = $defs[$mk]['name'] ?? $mk;
}
$methodText = implode(' / ', $methodNames);

// 接入说明里展示用的接口地址（未填写时给出占位，避免页面出现「?act=...」这种残缺示例）
$epView = wm_oauth_endpoint();
if ($epView === '') {
    $epView = '（尚未填写接口地址）';
}

wm_head('聚合登录');
?>
<?php if (!$ready): ?>
  <div class="alert err"><b>数据结构未升级：</b>缺少用户绑定表 <code><?= e(wm_t('user_oauth')) ?></code>，聚合登录不可用。请执行站点根目录的 <code>upgrade.sql</code> 后刷新本页。</div>
<?php endif; ?>
<?php if ($appkeyBroken): ?>
  <div class="alert err">APPKEY 无法解密（可能是站点密钥 AUTH_SALT 已变更），请重新填写应用 APPKEY。</div>
<?php endif; ?>

<div class="box">
  <div class="box-hd">
    <h2>使用公告</h2>
    <span class="hint">状态：<?= $cfg['on'] ? '<span class="st on">已开启</span>' : '<span class="st off">已关闭</span>' ?></span>
  </div>
  <div class="alert warn" style="margin-bottom:12px">
    <b>推荐聚合登录接口地址：</b><a href="https://u.770b.cn/" target="_blank" rel="nofollow noopener noreferrer">https://u.770b.cn/</a>
    ——<b>只填这个站点根地址即可</b>，本站会自动补 <code>/connect.php</code>。注册后获得应用 APPID 与 APPKEY，再把下方「登记的回调地址」加入该站点的回调白名单。
  </div>
  <div class="alert err" style="margin-bottom:0">
    <b>重要提示：</b>快捷登录开启后请勿随意更换登录 API 站点或更换应用 APPID / APPKEY。第三方用户标识（social_uid）与该站点、应用是强绑定的，
    一旦更换，之前以快捷登录注册的用户将<b>全部无法再登录</b>（数据仍保留，但再也匹配不上）。如确需更换，请先在原站点确认支持导出或迁移用户标识。
  </div>
</div>

<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2>快捷登录配置</h2></div>
    <form class="form" method="post" action="oauth.php" autocomplete="off">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="save">

      <div class="fr"><label>总开关</label><div class="fc">
        <label class="ck"><input type="checkbox" name="oauth_on" value="1" <?= $cfg['on'] ? 'checked' : '' ?>> 开启聚合（快捷）登录</label>
        <div class="fh">关闭后前台登录页不再显示第三方入口，已登录用户不受影响。</div>
      </div></div>

      <div class="fr"><label for="oa">接口地址</label><div class="fc">
        <input class="inp" type="text" id="oa" name="oauth_api" maxlength="500"
               value="<?= e($cfg['api']) ?>" placeholder="https://u.770b.cn/（只填站点根地址即可）">
        <div class="fh">
          <b>直接填站点根地址即可</b>，例如 <code>https://u.770b.cn/</code>，本站会自动补成 <code>https://u.770b.cn/connect.php</code>。
          若你的服务商接口路径不同，也可直接填写完整地址（如 <code>https://x.cn/api/login.php</code>），此时原样使用。
          本站会自动追加 <code>act</code> / <code>appid</code> / <code>appkey</code> / <code>type</code> / <code>redirect_uri</code> 等参数。
        </div>
      </div></div>

      <div class="fr"><label for="oi">应用 APPID</label><div class="fc">
        <input class="inp" type="text" id="oi" name="oauth_appid" maxlength="100"
               value="<?= e($cfg['appid']) ?>" placeholder="在聚合登录站点申请的应用 APPID">
      </div></div>

      <div class="fr"><label for="ok">应用 APPKEY</label><div class="fc">
        <input class="inp" type="password" id="ok" name="oauth_appkey" maxlength="200" value=""
               placeholder="<?= (string)wm_setting('oauth_appkey', '') !== '' ? '已设置，留空则不修改' : '请输入应用 APPKEY' ?>" autocomplete="new-password">
        <div class="fh">加密存储于数据库，后台不回显。</div>
      </div></div>

      <div class="fr"><label>登录方式</label><div class="fc">
        <?php foreach ($defs as $k => $d): ?>
          <label class="ck"><input type="checkbox" name="method_<?= e($k) ?>" value="1"
              <?= in_array($k, $cfg['methods'], true) ? 'checked' : '' ?>> <?= e($d['name']) ?>登录</label>
        <?php endforeach; ?>
        <div class="fh">只展示被勾选的方式；未勾选全部时无法保存。</div>
      </div></div>

      <div class="fr"><label>新用户</label><div class="fc">
        <label class="ck"><input type="checkbox" name="oauth_auto_register" value="1" <?= $cfg['auto'] ? 'checked' : '' ?>> 首次登录自动注册本站用户</label>
        <div class="fh">关闭后，只有已在本站注册过的用户才能用快捷登录（第三方标识需先与本站账号绑定）。</div>
      </div></div>

      <div class="fr"><label></label><div class="fc acts">
        <button class="btn" type="submit">保存</button>
      </div></div>
    </form>

    <form class="form" method="post" action="oauth.php" style="border-top:1px solid #f0f0f0;padding-top:14px">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="test">
      <div class="fr"><label>接口检测</label><div class="fc frow">
        <button class="btn ghost" type="submit">连接测试</button>
        <span class="fh" style="margin-top:0">真实跑一遍 <code>act=login</code>：能返回第三方授权地址即说明接口地址、APPID、APPKEY 三者都正确（比只探测域名可靠）。</span>
      </div></div>
    </form>
  </section>

  <section class="box">
    <div class="box-hd"><h2>运行状态</h2><span class="hint">供快速排查</span></div>
    <table class="kv">
      <tr><th>接口地址</th><td><code><?= e($epView) ?></code></td></tr>
      <tr><th>登记的回调地址</th><td><code><?= e(wm_oauth_callback_url()) ?></code><br><span class="hint">请在聚合登录站点把该地址加入「回调地址 / 域名白名单」</span></td></tr>
      <tr><th>已绑定用户</th><td><?= $ready ? $boundCount . ' 条' : '<span class="st wait">表未创建</span>' ?></td></tr>
      <tr><th>当前状态</th><td>
        <?php if (!$ready): ?><span class="st bad">数据表缺失</span>
        <?php elseif (wm_oauth_enabled()): ?><span class="st on">可用（<?= e($methodText) ?>）</span>
        <?php else: ?><span class="st off">未开启或配置不完整</span><?php endif; ?>
      </td></tr>
      <tr><th>前台入口</th><td><a href="../user/login.php" target="_blank" rel="noopener">打开前台登录页<?= wm_oauth_enabled() ? '' : '（当前不会显示第三方入口）' ?></a></td></tr>
    </table>
  </section>
</div>
<?php wm_foot();