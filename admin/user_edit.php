<?php
/**
 * 后台 - 编辑前台用户资料
 *
 * 登录账号不可修改；「重置密码」留空表示不改，填写后会 pass_version + 1，
 * 使该用户现有登录会话立即失效（wm_user() 每请求比对 pass_version）。
 */
declare(strict_types=1);

require __DIR__ . '/inc/layout.php';

$admin = wm_require_admin();
$userId = wm_input_int('id', 'GET', 0);
$user = $userId > 0
    ? wm_one('SELECT * FROM ' . wm_t('user') . ' WHERE id = ? LIMIT 1', [$userId])
    : null;
$error = '';

if ($user === null) {
    wm_flash(false, '用户不存在');
    wm_redirect('users.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    wm_csrf_check();

    $nickname = wm_input('nickname');
    $email = wm_input('email');
    $signature = wm_input('signature');
    $status = wm_input_int('status') === 1 ? 1 : 0;
    $newPassword = (string) ($_POST['new_password'] ?? '');

    if (($nickErr = wm_nickname_error($nickname)) !== '') {
        $error = $nickErr;
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } elseif (mb_strlen($signature) > 100) {
        $error = '签名不能超过 100 字';
    } elseif ($newPassword !== '' && ($weak = wm_password_weak($newPassword)) !== '') {
        $error = $weak;
    }

    if ($error === '') {
        if ($newPassword !== '') {
            wm_exec(
                'UPDATE ' . wm_t('user') . '
                 SET nickname = ?, email = ?, signature = ?, status = ?, password = ?, pass_version = pass_version + 1
                 WHERE id = ?',
                [$nickname, $email, $signature, $status, wm_password_hash($newPassword), $userId]
            );
        } else {
            wm_exec(
                'UPDATE ' . wm_t('user') . ' SET nickname = ?, email = ?, signature = ?, status = ? WHERE id = ?',
                [$nickname, $email, $signature, $status, $userId]
            );
        }

        // 重置密码属于高危操作，日志里要能区分出来，否则审计时与改昵称无法分辨
        wm_log('编辑用户', '用户 ID：' . $userId . ($newPassword !== '' ? '（含密码重置）' : ''));
        wm_flash(true, '用户资料已保存');
        wm_redirect('users.php');
    }
}

wm_head('编辑用户');
?>
<?php if ($error !== ''): ?>
    <div class="alert err"><?= e($error) ?></div>
<?php endif; ?>

<div class="box">
    <form class="form" method="post" autocomplete="off">
        <?= wm_csrf_field() ?>

        <div class="fr">
            <label>登录账号</label>
            <div class="fc"><input class="inp" value="<?= e((string) $user['username']) ?>" disabled></div>
        </div>

        <div class="fr">
            <label>昵称</label>
            <div class="fc"><input class="inp" name="nickname" maxlength="20" value="<?= e((string) $user['nickname']) ?>" required></div>
        </div>

        <div class="fr">
            <label>邮箱</label>
            <div class="fc"><input class="inp" type="email" name="email" maxlength="120" value="<?= e((string) $user['email']) ?>"></div>
        </div>

        <div class="fr">
            <label>签名</label>
            <div class="fc"><input class="inp" name="signature" maxlength="100" value="<?= e((string) $user['signature']) ?>"></div>
        </div>

        <div class="fr">
            <label>状态</label>
            <div class="fc">
                <label class="ck"><input type="checkbox" name="status" value="1" <?= (int) $user['status'] === 1 ? 'checked' : '' ?>> 允许登录</label>
            </div>
        </div>

        <div class="fr">
            <label>重置密码</label>
            <div class="fc">
                <input class="inp" type="password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" placeholder="留空表示不修改">
                <div class="fh">填写后会使该用户现有登录会话失效。</div>
            </div>
        </div>

        <div class="fr">
            <label></label>
            <div class="fc acts">
                <button class="btn" type="submit">保存用户</button>
                <a class="btn ghost" href="users.php">返回</a>
            </div>
        </div>
    </form>
</div>
<?php wm_foot();
