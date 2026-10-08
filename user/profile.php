<?php
declare(strict_types=1);

require __DIR__ . '/layout.php';
require_once WM_INC . '/oauth.php';

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();
    $action = wm_input('act');

    if ($action === 'profile') {
        $nickname = wm_input('nickname');
        $email = wm_input('email');
        $signature = wm_input('signature');

        if (($nickErr = wm_nickname_error($nickname)) !== '') {
            $error = $nickErr;
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确';
        } elseif (mb_strlen($signature) > 100) {
            $error = '签名不能超过 100 字';
        } else {
            wm_exec(
                'UPDATE ' . wm_t('user') . ' SET nickname = ?, email = ?, signature = ? WHERE id = ?',
                [$nickname, $email, $signature, (int) $user['id']]
            );
            wm_flash(true, '资料已保存');
            wm_redirect('profile.php');
        }
    } elseif ($action === 'password') {
        $oldPassword = (string) ($_POST['old_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password2'] ?? '');

        if (!password_verify($oldPassword, (string) $user['password'])) {
            $error = '原密码不正确';
        } elseif ($newPassword !== $confirmPassword) {
            $error = '两次新密码不一致';
        } elseif (($weak = wm_password_weak($newPassword)) !== '') {
            $error = $weak;
        } else {
            wm_exec(
                'UPDATE ' . wm_t('user') . ' SET password = ?, pass_version = pass_version + 1 WHERE id = ?',
                [wm_password_hash($newPassword), (int) $user['id']]
            );
            wm_user_logout();
            wm_flash(true, '密码已修改，请重新登录');
            wm_redirect('login.php');
        }
    } elseif ($action === 'oauth_unbind') {
        $bindId = wm_input_int('bind_id');
        $name = '';
        foreach (wm_oauth_user_binds((int) $user['id']) as $b) {
            if ((int) $b['id'] === $bindId) {
                $name = (string) $b['name'];
                break;
            }
        }
        if ($name === '') {
            wm_flash(false, '未找到该绑定记录');
        } elseif (wm_oauth_unbind((int) $user['id'], $bindId)) {
            wm_log('解绑快捷登录', (string) $user['username'] . ' 解绑 ' . $name);
            wm_flash(true, $name . '快捷登录已解绑');
        } else {
            wm_flash(false, '解绑失败，请稍后重试');
        }
        wm_redirect('profile.php');
    }
}

user_head('账号设置');
user_flash();
$userAvatar = wm_safe_display_path((string)$user['avatar']);
// 快捷登录绑定：仅在后台开启聚合登录时展示
$oauthReady = wm_oauth_ready();
$oauthOn = $oauthReady && wm_oauth_enabled();
$oauthDefs = wm_oauth_methods();
$binds = $oauthReady ? wm_oauth_user_binds((int)$user['id']) : [];
$boundProviders = array_column($binds, 'provider');
$oauthMethods = $oauthOn ? wm_oauth_cfg()['methods'] : [];
?>
<?php if ($error !== ''): ?>
    <div class="alert err"><?= e($error) ?></div>
<?php endif; ?>

<div class="two-col">
    <section class="panel">
        <div class="panel-title">基本信息</div>

        <form class="form" method="post" action="profile.php">
            <?= wm_csrf_field() ?>
            <input type="hidden" name="act" value="profile">

            <label>
                登录账号
                <input value="<?= e((string) $user['username']) ?>" disabled>
            </label>

            <label>
                头像
                <div class="avatar-editor" data-avatar-editor>
                    <div class="avatar-preview">
                        <?php if ($userAvatar !== ''): ?>
                            <img id="avatarPreview" src="<?= e(wm_file_url($userAvatar, '../')) ?>" alt="当前头像">
                        <?php else: ?>
                            <span id="avatarPreview" class="avatar-placeholder">暂无</span>
                        <?php endif; ?>
                    </div>
                    <div class="avatar-actions">
                        <button class="secondary" type="button" data-avatar-select>选择图片</button>
                        <span class="muted">JPG、PNG、GIF、WEBP，最大 <?= (int) wm_setting('max_image_mb', '10') ?>MB</span>
                    </div>
                    <input type="file" data-avatar-input accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                </div>
            </label>

            <label>
                昵称
                <input name="nickname" maxlength="20" value="<?= e((string) $user['nickname']) ?>" required>
            </label>

            <label>
                邮箱
                <input type="email" name="email" maxlength="120" value="<?= e((string) $user['email']) ?>">
            </label>

            <label>
                个性签名
                <input name="signature" maxlength="100" value="<?= e((string) $user['signature']) ?>">
            </label>

            <button class="primary" type="submit">保存资料</button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-title">修改密码</div>

        <form class="form" method="post" action="profile.php">
            <?= wm_csrf_field() ?>
            <input type="hidden" name="act" value="password">

            <label>
                原密码
                <input type="password" name="old_password" required autocomplete="current-password">
            </label>

            <label>
                新密码
                <input type="password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password">
            </label>

            <label>
                确认新密码
                <input type="password" name="new_password2" minlength="8" maxlength="72" required autocomplete="new-password">
            </label>

            <button class="primary" type="submit">修改密码</button>
    </form>
</section>

<?php if ($oauthReady && $oauthOn): ?>
<section class="panel">
    <div class="panel-title">快捷登录绑定</div>

    <?php if (!$binds): ?>
        <div class="alert warn" style="margin:0 0 12px">绑定后可用第三方账号直接登录本站，无需再记密码。绑定的是第三方账号身份，与当前用户名 / 密码账号是同一个账号。</div>
    <?php endif; ?>

    <?php foreach ($oauthMethods as $mk):
        if (!isset($oauthDefs[$mk])) { continue; }
        $md = $oauthDefs[$mk];
        $info = null;
        foreach ($binds as $b) { if ((string)$b['provider'] === $mk) { $info = $b; break; } }
        $bound = $info !== null; ?>
        <div class="fr oauth-bind">
            <label><?= e($md['name']) ?>快捷登录</label>
            <div class="fc frow">
                <?php if ($bound): ?>
                    <span class="st on">已绑定<?= (string)$info['nickname'] !== '' ? '：' . e((string)$info['nickname']) : '' ?></span>
                    <form method="post" action="profile.php" class="inline-form"
                          data-confirm="解绑后将无法用该第三方账号快捷登录本站，确定解绑？">
                        <?= wm_csrf_field() ?>
                        <input type="hidden" name="act" value="oauth_unbind">
                        <input type="hidden" name="bind_id" value="<?= (int)$info['id'] ?>">
                        <button class="btn sm ghost" type="submit">解绑</button>
                    </form>
                <?php else: ?>
                    <a class="btn sm" href="oauth.php?type=<?= e($mk) ?>&amp;act=bind">绑定<?= e($md['name']) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
</div>

<script>
    window.WM_USER = <?= ejs(['token' => wm_csrf_token()]) ?>;
</script>
<script src="../assets/js/user-profile.js?v=<?= e(WM_ASSET_VER) ?>"></script>
<?php user_foot();
