<?php
/**
 * 在线更新
 *
 * 两种来源（共用 includes/update.php 的应用逻辑）：
 *   1) 远程更新：从「更新通道」(manifest.json) 拉取最新版本并一键应用；
 *   2) 本地更新：管理员直接上传更新包 zip 应用（无中央服务器时可用）。
 *
 * 无论哪种，应用前都会自动备份被覆盖的文件，且 config.php / data/ / uploads/ / install/ 受保护。
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
require_once WM_INC . '/update.php';
$admin = wm_require_admin();

// 仅允许管理员访问，且所有写操作走 POST + CSRF
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    wm_require_post(true);
    $act = wm_input('act');

    // AJAX 检查更新
    if ($act === 'check') {
        $m = wm_update_fetch_manifest(wm_update_channel());
        if (!$m['ok']) {
            wm_json(false, $m['msg']);
        }
        $latest = (string) $m['data']['version'];
        $has = version_compare($latest, WM_VERSION, '>');
        $min = (string) ($m['data']['min_version'] ?? '');
        $tooLow = $min !== '' && version_compare(WM_VERSION, $min, '<');
        wm_json(true, 'ok', [
            'latest' => $latest,
            'current' => WM_VERSION,
            'has_update' => $has,
            'too_low' => $tooLow,
            'min_version' => $min,
            'changelog' => (string) ($m['data']['changelog'] ?? ''),
            'size' => (int) ($m['data']['size'] ?? 0),
            'time' => (string) ($m['data']['time'] ?? ''),
        ]);
    }

    // 远程更新：下载并应用
    if ($act === 'remote_update') {
        if (!wm_rate_limit('admin_update', 10, 3600, 'admin' . (int) $admin['id'])) {
            wm_flash(false, '操作过于频繁，请稍后再试');
            wm_redirect('update.php');
        }
        $m = wm_update_fetch_manifest(wm_update_channel());
        if (!$m['ok']) {
            wm_flash(false, $m['msg']);
            wm_redirect('update.php');
        }
        $latest = (string) $m['data']['version'];
        if (version_compare($latest, WM_VERSION, '<=')) {
            wm_flash(false, '当前已是最新版本（' . WM_VERSION . '）');
            wm_redirect('update.php');
        }
        $min = (string) ($m['data']['min_version'] ?? '');
        if ($min !== '' && version_compare(WM_VERSION, $min, '<')) {
            wm_flash(false, '当前版本过低，需先手动升级到 ' . $min . ' 以上，请使用「本地更新包」');
            wm_redirect('update.php');
        }
        $tmp = WM_DATA . '/tmp/update_' . wm_random(12) . '.zip';
        $dl = wm_update_download((string) $m['data']['package'], $tmp);
        if (!$dl['ok']) {
            wm_flash(false, $dl['msg']);
            wm_redirect('update.php');
        }
        if (!wm_update_verify_hash($tmp, (string) ($m['data']['hash'] ?? ''))) {
            @unlink($tmp);
            wm_flash(false, '更新包校验失败（哈希不一致，可能已损坏或被篡改）');
            wm_redirect('update.php');
        }
        $res = wm_update_apply_zip($tmp, $latest);
        @unlink($tmp);
        if (!$res['ok']) {
            wm_log('在线更新失败', $res['msg'], (int) $admin['id']);
            wm_flash(false, '更新失败：' . $res['msg'] . '（已自动备份，可回滚）');
        } else {
            wm_log('在线更新', '升级到 ' . $latest . '，应用 ' . $res['applied'] . ' 个文件', (int) $admin['id']);
            wm_flash(true, '已升级到 ' . $latest . '：' . $res['msg']);
        }
        wm_redirect('update.php');
    }

    // 本地更新：上传 zip 并应用
    if ($act === 'local_update') {
        if (!wm_rate_limit('admin_update', 10, 3600, 'admin' . (int) $admin['id'])) {
            wm_flash(false, '操作过于频繁，请稍后再试');
            wm_redirect('update.php');
        }
        if (empty($_FILES['package']) || !is_array($_FILES['package'])) {
            wm_flash(false, '未接收到更新包');
            wm_redirect('update.php');
        }
        $f = $_FILES['package'];
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            wm_flash(false, '更新包上传失败');
            wm_redirect('update.php');
        }
        $ext = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'zip') {
            wm_flash(false, '更新包须为 .zip 文件');
            wm_redirect('update.php');
        }
        // 校验为合法 zip
        if (!class_exists('ZipArchive')) {
            wm_flash(false, '服务器未启用 ZipArchive 扩展，无法解压更新包');
            wm_redirect('update.php');
        }
        $za = new ZipArchive();
        if ($za->open($f['tmp_name']) !== true) {
            wm_flash(false, '更新包不是有效的 zip 文件');
            wm_redirect('update.php');
        }
        // 尝试从包内 VERSION 文件读取目标版本号
        $newVersion = '';
        $vEntry = $za->getFromName('VERSION');
        if ($vEntry !== false) {
            $newVersion = trim((string) $vEntry);
        }
        $za->close();

        $tmp = WM_DATA . '/tmp/local_update_' . wm_random(12) . '.zip';
        if (!@move_uploaded_file($f['tmp_name'], $tmp)) {
            wm_flash(false, '更新包暂存失败');
            wm_redirect('update.php');
        }
        $res = wm_update_apply_zip($tmp, $newVersion);
        @unlink($tmp);
        if (!$res['ok']) {
            wm_log('本地更新失败', $res['msg'], (int) $admin['id']);
            wm_flash(false, '更新失败：' . $res['msg'] . '（已自动备份，可回滚）');
        } else {
            wm_log('本地更新', '应用 ' . $res['applied'] . ' 个文件' . ($newVersion !== '' ? '，升级到 ' . $newVersion : ''), (int) $admin['id']);
            wm_flash(true, '更新完成：' . $res['msg'] . ($newVersion !== '' ? '（' . $newVersion . '）' : ''));
        }
        wm_redirect('update.php');
    }

    wm_flash(false, '未知操作');
    wm_redirect('update.php');
}

wm_head('在线更新');
$currentVer = WM_VERSION;
?>
<div class="grid2">
    <section class="box">
        <div class="box-hd"><h2>版本信息</h2></div>
        <table class="kv">
            <tr><th>当前版本</th><td><b>V<?= e($currentVer) ?></b></td></tr>
            <tr><th>最新版本</th><td><span id="latestVer">检测中…</span></td></tr>
        </table>
        <div class="acts" style="margin-top:14px">
            <button class="btn" type="button" id="updateBtn">立即更新</button>
            <span id="checkState" class="hint"></span>
        </div>
        <div id="checkResult" style="margin-top:12px"></div>
    </section>

    <section class="box">
        <div class="box-hd"><h2>本地更新包</h2><span class="hint">无网络或中央通道不可用时，手动上传 zip 应用</span></div>
        <form class="form" method="post" action="update.php" enctype="multipart/form-data">
            <?= wm_csrf_field() ?>
            <input type="hidden" name="act" value="local_update">
            <div class="fr"><label for="pkg">更新包</label><div class="fc frow">
                <input class="inp" type="file" id="pkg" name="package" accept=".zip,application/zip" required style="max-width:420px">
                <button class="btn" type="submit" data-confirm="更新将覆盖 zip 内文件（已自动备份），确定继续？">上传并更新</button>
            </div></div>
            <div class="fh">更新包内可含 <code>VERSION</code> 文件声明新版本号；<code>upgrade.sql</code> 会在更新后自动执行。config.php / data/ / uploads/ / install/ 不会被覆盖。</div>
        </form>
    </section>
</div>
<?php wm_foot(); ?>

<script>
(function () {
    var btn = document.getElementById('updateBtn');
    if (!btn) { return; }
    var res = document.getElementById('checkResult');
    var state = document.getElementById('checkState');
    var latestEl = document.getElementById('latestVer');
    var token = (window.WMA && window.WMA.token) || '';

    function check() {
        var fd = new FormData();
        fd.append('_token', token);
        fd.append('act', 'check');
        return fetch('update.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function doUpdate() {
        if (!window.confirm('即将执行在线更新，更新前已自动备份被覆盖的文件，确定继续？')) { return; }
        var f2 = new FormData();
        f2.append('_token', token);
        f2.append('act', 'remote_update');
        btn.disabled = true; btn.textContent = '更新中…';
        fetch('update.php', { method: 'POST', body: f2, credentials: 'same-origin' })
            .then(function () { window.location.href = 'update.php'; })
            .catch(function () { window.location.href = 'update.php'; });
    }

    // 页面加载即检查，回填最新版本号
    check().then(function (j) {
        if (j && j.ok && latestEl) { latestEl.textContent = 'V' + j.data.latest; }
        else if (latestEl) { latestEl.textContent = '（获取失败）'; }
    }).catch(function () { if (latestEl) { latestEl.textContent = '（获取失败）'; } });

    btn.addEventListener('click', function () {
        btn.disabled = true;
        if (state) { state.textContent = '正在检查更新…'; }
        res.innerHTML = '';
        check().then(function (j) {
            btn.disabled = false;
            if (state) { state.textContent = ''; }
            if (!j.ok) { res.innerHTML = '<div class="alert err">' + (j.msg || '检查失败') + '</div>'; return; }
            var d = j.data;
            var html = '';
            if (!d.has_update) {
                html += '<div class="alert ok">当前已是最新版本（V' + d.current + '）</div>';
                if (latestEl) { latestEl.textContent = 'V' + d.latest; }
            } else if (d.too_low) {
                html += '<div class="alert warn">检测到新版本 V' + d.latest + '，但当前版本过低（需先升级到 ' + d.min_version + ' 以上），请使用「本地更新包」。</div>';
            } else {
                html += '<div class="alert ok">发现新版本：V' + d.latest + '（当前 V' + d.current + '）</div>';
                if (d.time) { html += '<div class="hint">发布时间：' + d.time + '</div>'; }
                if (d.changelog) {
                    html += '<pre style="white-space:pre-wrap;background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px;margin:10px 0;font:13px/1.7 monospace">' + d.changelog.replace(/[&<>]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;'}[c]; }) + '</pre>';
                }
                html += '<button class="btn" type="button" id="doUpdate">立即更新到 V' + d.latest + '</button>';
            }
            res.innerHTML = html;
            var du = document.getElementById('doUpdate');
            if (du) { du.addEventListener('click', doUpdate); }
        }).catch(function () { btn.disabled = false; if (state) { state.textContent = ''; } res.innerHTML = '<div class="alert err">检查请求失败</div>'; });
    });
})();
</script>
