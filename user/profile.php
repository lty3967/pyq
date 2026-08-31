<?php
declare(strict_types=1);

require __DIR__ . '/layout.php';

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();
    $action = wm_input('act');

    if ($action === 'profile') {
        $nickname = wm_input('nickname');
        $email = wm_input('email');
        $signature = wm_input('signature');

        if ($nickname === '' || mb_strlen($nickname) > 20) {
            $error = '昵称需为 1-20 字';
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
    }
}

user_head('账号设置');
user_flash();
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
                        <?php if (!empty($user['avatar'])): ?>
                            <img id="avatarPreview" src="../<?= e((string) $user['avatar']) ?>" alt="当前头像">
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
</div>

<script>
    window.WM_USER = <?= ejs(['token' => wm_csrf_token()]) ?>;
</script>
<script src="../assets/js/user-profile.js?v=<?= e(WM_VERSION) ?>"></script>
<?php user_foot();
