<?php
/**
 * 管理员信息编辑
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
$admin = wm_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act');

    if ($act === 'profile') {
        $nickname = wm_input('nickname');
        $email = wm_input('email');
        $signature = wm_input('signature');
        $avatar = wm_input('avatar');

        $err = '';
        if ($nickname === '' || mb_strlen($nickname) > 20) {
            $err = '昵称长度需为 1-20 字';
        } elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100)) {
            $err = '邮箱格式不正确';
        } elseif (mb_strlen($signature) > 100) {
            $err = '个性签名不能超过 100 字';
        }
        if ($avatar !== '' && wm_safe_display_path($avatar) === '') {
            $avatar = (string)$admin['avatar'];
        }

        if ($err !== '') {
            wm_flash(false, $err);
        } else {
            wm_exec('UPDATE ' . wm_t('admin') . ' SET nickname = ?, email = ?, signature = ?, avatar = ? WHERE id = ?',
                [$nickname, $email, $signature, $avatar, (int)$admin['id']]);
            // 同步前台展示信息
            wm_setting_set('owner_name', $nickname);
            wm_setting_set('owner_signature', $signature);
            wm_setting_set('owner_avatar', $avatar);
            wm_log('修改资料', '昵称：' . $nickname);
            wm_flash(true, '资料已保存');
        }
        wm_redirect('profile.php');
    }

    if ($act === 'password') {
        $old = (string)($_POST['old_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $new2 = (string)($_POST['new_password2'] ?? '');

        if (!wm_rate_limit('chpwd', 5, 900, 'admin' . (int)$admin['id'])) {
            wm_flash(false, '尝试过于频繁，请稍后再试');
            wm_redirect('profile.php');
        }
        if (!password_verify($old, (string)$admin['password'])) {
            wm_log('修改密码失败', '原密码错误');
            wm_flash(false, '原密码不正确');
        } elseif ($new !== $new2) {
            wm_flash(false, '两次输入的新密码不一致');
        } elseif (($weak = wm_password_weak($new)) !== '') {
            wm_flash(false, $weak);
        } elseif (password_verify($new, (string)$admin['password'])) {
            wm_flash(false, '新密码不能与原密码相同');
        } else {
            wm_exec('UPDATE ' . wm_t('admin') . ' SET password = ?, pass_version = pass_version + 1 WHERE id = ?',
                [wm_password_hash($new), (int)$admin['id']]);
            wm_log('修改密码', '成功');

            // 邮件告知
            $to = (string)($admin['email'] !== '' ? $admin['email'] : wm_setting('notify_email', ''));
            if ($to !== '') {
                try {
                    require_once WM_INC . '/mailer.php';
                    $m = new WmMailer();
                    if ($m->configured()) {
                        $m->send($to, '【' . wm_setting('site_name', '朋友圈') . '】后台密码已修改',
                            wm_mail_template('密码变更提醒',
                                '<p>您的后台账号 <b>' . e((string)$admin['username']) . '</b> 的登录密码已于 '
                                . date('Y-m-d H:i:s') . ' 修改。</p><p>操作 IP：' . e(wm_client_ip()) . '</p>'
                                . '<p>若非本人操作，请立即重置密码并检查服务器安全。</p>'));
                    }
                } catch (Throwable $e) {
                    error_log('pwd mail fail: ' . $e->getMessage());
                }
            }

            wm_admin_logout();
            wm_session_start();
            wm_flash(true, '密码已修改，请使用新密码重新登录');
            wm_redirect('login.php');
        }
        wm_redirect('profile.php');
    }
}

$logs = wm_all('SELECT action, detail, ip, created_at FROM ' . wm_t('log') . '
                WHERE admin_id = ? ORDER BY id DESC LIMIT 10', [(int)$admin['id']]);

wm_head('管理员信息');
$adminAvatar = wm_safe_display_path((string)$admin['avatar']);
?>
<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2>基本资料</h2><span class="hint">昵称/头像/签名同步至前台</span></div>
    <form class="form" method="post" action="profile.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="profile">

      <div class="fr"><label>登录账号</label><div class="fc">
        <input class="inp" type="text" value="<?= e((string)$admin['username']) ?>" disabled>
        <div class="fh">账号创建后不可修改</div>
      </div></div>

      <div class="fr"><label for="nickname">昵称</label><div class="fc">
        <input class="inp" type="text" id="nickname" name="nickname" maxlength="20" required value="<?= e((string)$admin['nickname']) ?>">
      </div></div>

      <div class="fr"><label for="email">邮箱</label><div class="fc">
        <input class="inp" type="email" id="email" name="email" maxlength="100" value="<?= e((string)$admin['email']) ?>">
        <div class="fh">用于接收安全提醒与评论通知</div>
      </div></div>

      <div class="fr"><label for="signature">个性签名</label><div class="fc">
        <input class="inp" type="text" id="signature" name="signature" maxlength="100" value="<?= e((string)$admin['signature']) ?>">
      </div></div>

      <div class="fr"><label>头像</label><div class="fc">
        <div data-single-upload="1" data-target="avatarPath" class="frow" style="align-items:center">
          <img class="sp-img" src="<?= $adminAvatar !== '' ? '../' . e($adminAvatar) : '' ?>"
               alt="" style="width:64px;height:64px;border-radius:8px;object-fit:cover;border:1px solid #ebebeb;<?= $adminAvatar === '' ? 'display:none' : '' ?>">
          <button class="btn sm ghost sp-btn" type="button">选择图片</button>
          <button class="btn sm ghost sp-clear" type="button">清除</button>
          <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
        </div>
        <input type="hidden" name="avatar" id="avatarPath" value="<?= e($adminAvatar) ?>">
        <div class="fh">未设置头像时前台显示昵称首字色块</div>
      </div></div>

      <div class="fr"><label></label><div class="fc acts"><button class="btn" type="submit">保存资料</button></div></div>
    </form>
  </section>

  <section class="box">
    <div class="box-hd"><h2>修改密码</h2><span class="hint">修改后需重新登录</span></div>
    <form class="form" method="post" action="profile.php" autocomplete="off">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="password">
      <div class="fr"><label for="op">原密码</label><div class="fc">
        <input class="inp" type="password" id="op" name="old_password" required autocomplete="current-password">
      </div></div>
      <div class="fr"><label for="np">新密码</label><div class="fc">
        <input class="inp" type="password" id="np" name="new_password" required autocomplete="new-password" minlength="8" maxlength="72">
        <div class="fh">至少 8 位，需包含大写字母、小写字母、数字、符号中至少三类</div>
      </div></div>
      <div class="fr"><label for="np2">确认新密码</label><div class="fc">
        <input class="inp" type="password" id="np2" name="new_password2" required autocomplete="new-password" minlength="8" maxlength="72">
      </div></div>
      <div class="fr"><label></label><div class="fc acts">
        <button class="btn danger" type="submit" data-confirm="修改密码后所有登录会话将失效，需重新登录，确认继续？">修改密码</button>
      </div></div>
    </form>

    <div class="box-hd mt"><h2>账号状态</h2></div>
    <table class="kv">
      <tr><th>角色</th><td><?= e((string)$admin['role']) === 'super' ? '超级管理员' : '管理员' ?></td></tr>
      <tr><th>累计登录</th><td><?= (int)$admin['login_count'] ?> 次</td></tr>
      <tr><th>上次登录</th><td><?= $admin['last_login_at'] !== null ? e((string)$admin['last_login_at']) : '—' ?></td></tr>
      <tr><th>上次登录 IP</th><td><?= e((string)$admin['last_login_ip']) ?: '—' ?></td></tr>
      <tr><th>创建时间</th><td><?= e((string)$admin['created_at']) ?></td></tr>
    </table>
  </section>
</div>

<div class="box">
  <div class="box-hd"><h2>我的最近操作</h2><a class="hint" href="logs.php">全部日志 →</a></div>
  <table class="tb">
    <thead><tr><th style="width:110px">操作</th><th>详情</th><th style="width:130px">IP</th><th style="width:140px">时间</th></tr></thead>
    <tbody>
    <?php if (!$logs): ?><tr><td colspan="4" class="none">暂无记录</td></tr><?php endif; ?>
    <?php foreach ($logs as $l): ?>
      <tr>
        <td><?= e((string)$l['action']) ?></td>
        <td><?= e(wm_cut((string)$l['detail'], 60)) ?: '—' ?></td>
        <td><span class="hint"><?= e((string)$l['ip']) ?></span></td>
        <td><span class="hint"><?= e((string)$l['created_at']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php wm_foot();
