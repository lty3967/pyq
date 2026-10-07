<?php
/**
 * 发信功能：SMTP 配置 + 发送邮件 + 发信记录
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
require_once WM_INC . '/mailer.php';
$admin = wm_require_admin();

$traceOut = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act');

    // ---------- 保存 SMTP 配置 ----------
    if ($act === 'smtp') {
        $host = wm_input('smtp_host');
        $port = wm_input_int('smtp_port');
        $user = wm_input('smtp_user');
        $pass = (string)($_POST['smtp_pass'] ?? '');
        $secure = wm_input('smtp_secure');
        $from = wm_input('smtp_from');
        $fromName = wm_input('smtp_from_name');
        $notify = wm_input('notify_email');

        $err = '';
        if ($host !== '' && !preg_match('/^[A-Za-z0-9\.\-]{1,255}$/', $host)) {
            $err = 'SMTP 服务器地址格式无效';
        } elseif ($port < 1 || $port > 65535) {
            $err = 'SMTP 端口无效';
        } elseif (!in_array($secure, ['ssl', 'tls', ''], true)) {
            $err = '加密方式无效';
        } elseif ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $err = '发件人邮箱格式无效';
        } elseif ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL)) {
            $err = '通知接收邮箱格式无效';
        } elseif (mb_strlen($fromName) > 40) {
            $err = '发件人名称不能超过 40 字';
        }

        if ($err !== '') {
            wm_flash(false, $err);
        } else {
            wm_setting_set('smtp_host', $host);
            wm_setting_set('smtp_port', (string)$port);
            wm_setting_set('smtp_user', $user);
            // 密码留空表示保留原值；非空则加密存储
            if ($pass !== '') {
                wm_setting_set('smtp_pass', wm_secret_encode($pass));
            }
            wm_setting_set('smtp_secure', $secure);
            wm_setting_set('smtp_from', $from !== '' ? $from : $user);
            wm_setting_set('smtp_from_name', $fromName);
            wm_setting_set('notify_email', $notify);
            wm_setting_set('notify_on_comment', wm_input_int('notify_on_comment') === 1 ? '1' : '0');
            wm_setting_set('notify_on_update', wm_input_int('notify_on_update') === 1 ? '1' : '0');
            wm_log('保存 SMTP 配置', $host . ':' . $port);
            wm_flash(true, 'SMTP 配置已保存');
        }
        wm_redirect('mail.php');
    }

    // ---------- 发送测试信 ----------
    if ($act === 'test') {
        $to = wm_input('to');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            wm_flash(false, '测试收件地址无效');
            wm_redirect('mail.php');
        }
        if (!wm_rate_limit('mail_test', 10, 600, 'admin' . (int)$admin['id'])) {
            wm_flash(false, '测试发送过于频繁，请稍后再试');
            wm_redirect('mail.php');
        }
        $mailer = new WmMailer();
        $res = $mailer->send($to, '【测试】' . wm_setting('site_name', '朋友圈') . ' 邮件配置检测',
            wm_mail_template('邮件配置测试成功',
                '<p>如果你收到这封邮件，说明 SMTP 配置正确可用。</p>'
                . '<p>发送时间：' . date('Y-m-d H:i:s') . '</p>'));
        $traceOut = $mailer->trace();
        wm_exec('INSERT INTO ' . wm_t('mail') . ' (to_mail, subject, body, status, result, admin_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$to, '邮件配置检测', '(测试邮件)', $res['ok'] ? 1 : 2, mb_substr((string)$res['msg'], 0, 400), (int)$admin['id']]);
        wm_log('发送测试邮件', $to . ' → ' . ($res['ok'] ? '成功' : $res['msg']));
        $_SESSION['_mail_trace'] = $traceOut;
        wm_flash((bool)$res['ok'], ($res['ok'] ? '测试邮件已发送至 ' : '发送失败：') . ($res['ok'] ? $to : $res['msg']));
        wm_redirect('mail.php');
    }

    // ---------- 发送自定义邮件 ----------
    if ($act === 'send') {
        $toRaw = (string)($_POST['to'] ?? '');
        $subject = wm_input('subject');
        $content = (string)($_POST['content'] ?? '');
        $isHtml = wm_input_int('is_html') === 1;

        $tos = [];
        foreach (preg_split('/[\r\n,;]+/', $toRaw) ?: [] as $t) {
            $t = trim($t);
            if ($t !== '' && filter_var($t, FILTER_VALIDATE_EMAIL) && !in_array($t, $tos, true)) {
                $tos[] = $t;
            }
            if (count($tos) >= 50) { break; }
        }

        if (!$tos) {
            wm_flash(false, '请填写至少一个有效收件人邮箱');
        } elseif ($subject === '' || mb_strlen($subject) > 100) {
            wm_flash(false, '邮件主题需为 1-100 字');
        } elseif (trim($content) === '' || mb_strlen($content) > 20000) {
            wm_flash(false, '邮件内容需为 1-20000 字');
        } elseif (!wm_rate_limit('mail_send', 50, 3600, 'admin' . (int)$admin['id'])) {
            wm_flash(false, '发信次数已达上限（每小时 50 封），请稍后再试');
        } else {
            // HTML 模式下过滤危险标签，防止后台自身 XSS 与钓鱼滥用
            if ($isHtml) {
                $body = wm_mail_sanitize($content);
            } else {
                $body = wm_mail_template($subject, nl2br(e($content), false));
            }
            $mailer = new WmMailer();
            $res = $mailer->send($tos, $subject, $body, $isHtml ? '' : $content);
            $traceOut = $mailer->trace();
            $_SESSION['_mail_trace'] = $traceOut;
            wm_exec('INSERT INTO ' . wm_t('mail') . ' (to_mail, subject, body, status, result, admin_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [mb_substr(implode(',', $tos), 0, 480), $subject, mb_substr($body, 0, 60000),
                 $res['ok'] ? 1 : 2, mb_substr((string)$res['msg'], 0, 400), (int)$admin['id']]);
            wm_log('发送邮件', implode(',', $tos) . ' → ' . ($res['ok'] ? '成功' : $res['msg']));
            wm_flash((bool)$res['ok'], $res['ok'] ? ('邮件已发送给 ' . count($tos) . ' 位收件人') : ('发送失败：' . $res['msg']));
        }
        wm_redirect('mail.php');
    }

    if ($act === 'clear') {
        wm_exec('DELETE FROM ' . wm_t('mail'));
        wm_log('清空发信记录', '');
        wm_flash(true, '发信记录已清空');
        wm_redirect('mail.php');
    }
}

/** 邮件 HTML 白名单过滤 */
function wm_mail_sanitize(string $html): string
{
    // 去除脚本、样式、iframe、事件属性与 javascript: 协议
    $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*>.*?<\s*/\s*\1\s*>#is', '', $html) ?? $html;
    $html = preg_replace('#<\s*(script|style|iframe|object|embed|form|link|meta|base)\b[^>]*/?>#i', '', $html) ?? $html;
    // 事件属性：用 \b（词边界）而非 \s，可同时覆盖空白分隔 <img onerror> 与斜杠分隔 <img/onerror>
    $html = preg_replace('#\bon[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html) ?? $html;
    // 危险协议：先 html_entity_decode 再去掉空白与控制字符，最后匹配，
    // 否则 "java\tscript:"、"java&#9;script:"、"javascript&colon;" 等实体变体可绕过
    $html = preg_replace_callback(
        '#(href|src)\s*=\s*(["\'])(.*?)\2#is',
        static function ($m) {
            $raw = $m[3];
            $decoded = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $compact = preg_replace('/[\x00-\x20]/', '', $decoded) ?? $decoded;
            $val = preg_match('#^(javascript|vbscript|data)\s*:#i', $compact) ? '#' : $raw;
            return $m[1] . '=' . $m[2] . $val . $m[2];
        },
        $html
    ) ?? $html;
    return $html;
}

$mails = wm_all('SELECT id, to_mail, subject, status, result, created_at FROM ' . wm_t('mail') . ' ORDER BY id DESC LIMIT 20');
$trace = $_SESSION['_mail_trace'] ?? [];
unset($_SESSION['_mail_trace']);
$smtpConfigured = (new WmMailer())->configured();
// 密码已设置但无法解密（如 AUTH_SALT 变更），需重新填写，否则发信会静默失败
$smtpPassBroken = (string)wm_setting('smtp_pass', '') !== ''
    && wm_secret_decode((string)wm_setting('smtp_pass', '')) === '';

wm_head('发信功能');
?>
<?php if (!$smtpConfigured): ?>
  <div class="alert warn">SMTP 尚未配置完整，请先填写下方服务器信息后再发信。</div>
<?php endif; ?>
<?php if ($smtpPassBroken): ?>
  <div class="alert err">SMTP 密码无法解密（可能是站点密钥已变更），请重新填写「密码 / 授权码」后再发信。</div>
<?php endif; ?>

<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2>SMTP 服务器配置</h2><span class="hint">密码加密传输、留空不改</span></div>
    <form class="form" method="post" action="mail.php" autocomplete="off">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="smtp">
      <div class="fr"><label for="sh">服务器</label><div class="fc">
        <input class="inp" type="text" id="sh" name="smtp_host" maxlength="255" value="<?= e((string)wm_setting('smtp_host', '')) ?>" placeholder="如 smtp.qq.com">
      </div></div>
      <div class="fr"><label for="sp">端口 / 加密</label><div class="fc frow">
        <input class="inp" type="number" id="sp" name="smtp_port" min="1" max="65535" value="<?= (int)wm_setting('smtp_port', '465') ?>" style="max-width:110px">
        <select class="inp" name="smtp_secure" style="max-width:150px">
          <?php $sec = (string)wm_setting('smtp_secure', 'ssl'); ?>
          <option value="ssl" <?= $sec === 'ssl' ? 'selected' : '' ?>>SSL（465）</option>
          <option value="tls" <?= $sec === 'tls' ? 'selected' : '' ?>>STARTTLS（587）</option>
          <option value="" <?= $sec === '' ? 'selected' : '' ?>>不加密（25）</option>
        </select>
      </div></div>
      <div class="fr"><label for="su">账号</label><div class="fc">
        <input class="inp" type="text" id="su" name="smtp_user" maxlength="120" value="<?= e((string)wm_setting('smtp_user', '')) ?>" autocomplete="off">
      </div></div>
      <div class="fr"><label for="sw">密码 / 授权码</label><div class="fc">
        <input class="inp" type="password" id="sw" name="smtp_pass" maxlength="200" value="" placeholder="<?= (string)wm_setting('smtp_pass', '') !== '' ? '已设置，留空则不修改' : '请输入授权码' ?>" autocomplete="new-password">
        <div class="fh">QQ / 163 等邮箱请使用「授权码」而非登录密码</div>
      </div></div>
      <div class="fr"><label for="sf">发件地址</label><div class="fc">
        <input class="inp" type="email" id="sf" name="smtp_from" maxlength="120" value="<?= e((string)wm_setting('smtp_from', '')) ?>" placeholder="留空则同账号">
      </div></div>
      <div class="fr"><label for="sn">发件人名称</label><div class="fc">
        <input class="inp" type="text" id="sn" name="smtp_from_name" maxlength="40" value="<?= e((string)wm_setting('smtp_from_name', '')) ?>">
      </div></div>
      <div class="fr"><label for="ne">通知接收邮箱</label><div class="fc">
        <input class="inp" type="email" id="ne" name="notify_email" maxlength="120" value="<?= e((string)wm_setting('notify_email', '')) ?>">
        <label class="ck" style="margin-top:8px"><input type="checkbox" name="notify_on_comment" value="1" <?= (string)wm_setting('notify_on_comment', '0') === '1' ? 'checked' : '' ?>> 有新评论时邮件通知我</label>
        <label class="ck" style="margin-top:4px"><input type="checkbox" name="notify_on_update" value="1" <?= (string)wm_setting('notify_on_update', '1') === '1' ? 'checked' : '' ?>> 检测到新版本时邮件通知我（同一版本只发一次）</label>
        <div class="fh">留空则使用当前管理员账号邮箱；需先在上方配置好 SMTP 才能发出。</div>
      </div></div>
      <div class="fr"><label></label><div class="fc acts"><button class="btn" type="submit">保存配置</button></div></div>
    </form>

    <form class="form" method="post" action="mail.php" style="border-top:1px solid #f0f0f0;padding-top:14px">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="test">
      <div class="fr"><label for="tt">测试收件人</label><div class="fc frow">
        <input class="inp" type="email" id="tt" name="to" required value="<?= e((string)wm_setting('notify_email', '')) ?>" placeholder="接收测试邮件的地址">
        <button class="btn ghost" type="submit">发送测试邮件</button>
      </div></div>
    </form>

    <?php if ($trace): ?>
      <div class="box-hd mt"><h2>最近一次 SMTP 会话</h2></div>
      <pre style="background:#1f2430;color:#c5cad4;padding:12px;border-radius:8px;font-size:12px;overflow:auto;max-height:220px;line-height:1.6"><?php
        foreach (array_slice($trace, 0, 40) as $line) { echo e((string)$line) . "\n"; }
      ?></pre>
    <?php endif; ?>
  </section>

  <section class="box">
    <div class="box-hd"><h2>发送邮件</h2><span class="hint">每小时最多 50 封</span></div>
    <form class="form" method="post" action="mail.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="send">
      <div class="fr"><label for="to">收件人</label><div class="fc">
        <textarea class="inp" id="to" name="to" rows="3" required placeholder="每行一个邮箱，或用逗号分隔，最多 50 个"></textarea>
      </div></div>
      <div class="fr"><label for="sj">主题</label><div class="fc">
        <input class="inp" type="text" id="sj" name="subject" maxlength="100" required>
      </div></div>
      <div class="fr"><label for="ct">内容</label><div class="fc">
        <textarea class="inp" id="ct" name="content" rows="10" maxlength="20000" required data-counter="mCount"></textarea>
        <div class="fh"><span id="mCount"></span></div>
        <label class="ck" style="margin-top:6px"><input type="checkbox" name="is_html" value="1"> 内容为 HTML（脚本、事件属性会被自动清除）</label>
      </div></div>
      <div class="fr"><label></label><div class="fc acts">
        <button class="btn" type="submit" data-confirm="确认发送该邮件？" <?= $smtpConfigured ? '' : 'disabled' ?>>发送</button>
      </div></div>
    </form>
  </section>
</div>

<div class="box">
  <div class="box-hd">
    <h2>发信记录</h2>
    <form method="post" action="mail.php" style="margin:0">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="clear">
      <button class="btn sm ghost" type="submit" data-confirm="确认清空全部发信记录？">清空记录</button>
    </form>
  </div>
  <table class="tb">
    <thead><tr><th style="width:44px">ID</th><th style="width:220px">收件人</th><th>主题</th><th style="width:70px">状态</th><th>结果</th><th style="width:140px">时间</th></tr></thead>
    <tbody>
    <?php if (!$mails): ?><tr><td colspan="6" class="none">暂无发信记录</td></tr><?php endif; ?>
    <?php foreach ($mails as $m): ?>
      <tr>
        <td><?= (int)$m['id'] ?></td>
        <td><?= e(wm_cut((string)$m['to_mail'], 34)) ?></td>
        <td><?= e(wm_cut((string)$m['subject'], 24)) ?></td>
        <td><?= (int)$m['status'] === 1 ? '<span class="st on">成功</span>' : '<span class="st bad">失败</span>' ?></td>
        <td><span class="hint"><?= e(wm_cut((string)$m['result'], 46)) ?: '—' ?></span></td>
        <td><span class="hint"><?= e((string)$m['created_at']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php wm_foot();
