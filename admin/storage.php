<?php
/**
 * 存储类型设置
 *
 * 支持本地存储与 9 种第三方存储；表单按类型动态显示对应参数，
 * 提供「保存」「连接测试」与「开通地址 / 文档介绍」三个按钮。
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
require_once WM_INC . '/storage.php';
$admin = wm_require_admin();

$types = wm_storage_types();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act', 'POST', 'save');
    $type = wm_input('storage_type');
    if (!isset($types[$type])) {
        wm_flash(false, '未知的存储类型');
        wm_redirect('storage.php');
    }

    // 参数按当前类型过滤，避免把别的类型的字段写进配置。
    // 表单里所有类型的输入框同时存在于 DOM（靠 JS 隐藏），所以 name 必须带类型前缀，
    // 否则同名字段（如各云存储都有 ak）会以「最后一个」为准而串台。
    $in = [];
    foreach ($types[$type]['fields'] as $k => $f) {
        $raw = $_POST['p_' . $type . '_' . $k] ?? '';
        $in[$k] = is_scalar($raw) ? trim((string)$raw) : '';
    }

    if ($act === 'test') {
        // 用「页面上刚填的」参数测试；敏感字段留空时沿用数据库里已保存的值，
        // 否则每次测连接都要把密钥重新敲一遍。
        $cfg = [];
        $secretKeys = wm_storage_secret_fields($type);
        $savedRaw = json_decode((string)wm_setting('storage_opts', ''), true);
        if (is_array($savedRaw)) {
            foreach ($savedRaw as $sk => $sv) {
                if (in_array((string)$sk, $secretKeys, true) && is_scalar($sv)) {
                    $cfg[(string)$sk] = wm_secret_decode((string)$sv);
                }
            }
        }
        foreach ($types[$type]['fields'] as $k => $f) {
            $v = isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
            if ($v !== '') {
                $cfg[$k] = $v;
            } elseif (!isset($cfg[$k])) {
                $cfg[$k] = (string)($f['def'] ?? '');
            }
        }
        $drv = wm_storage_make($type, $cfg);
        if ($drv === null) {
            wm_flash(false, '无法创建该存储类型的驱动');
            wm_redirect('storage.php');
        }
        if (!wm_rate_limit('storage_test', 20, 600, 'admin' . (int)$admin['id'])) {
            wm_flash(false, '连接测试过于频繁，请稍后再试');
            wm_redirect('storage.php');
        }
        $r = $drv->test();
        wm_log('测试存储连接', $types[$type]['name'] . ' → ' . ($r['ok'] ? '成功' : (string)$r['msg']));
        wm_flash((bool)$r['ok'], (string)$r['msg']);
        wm_redirect('storage.php');
    }

    if ($act === 'save') {
        $r = wm_storage_save($type, $in);
        if (!$r['ok']) {
            wm_flash(false, (string)$r['msg']);
        } else {
            wm_log('修改存储设置', '类型：' . $types[$type]['name']);
            wm_flash(true, '存储设置已保存，新的上传将使用「' . $types[$type]['name'] . '」');
        }
        wm_redirect('storage.php');
    }
}

$curType = wm_storage_type();
$cfg = wm_storage_cfg();
$stored = json_decode((string)wm_setting('storage_opts', ''), true);
$isRemote = $curType !== 'local';

wm_head('存储设置');
?>
<div class="alert warn">
  <b>说明：</b>图片 / 视频仍然先在服务器本地完成「格式校验 + 重编码 + 生成缩略图」，再由本系统推送到下方配置的存储；
  存储类型只影响<b>之后</b>的新上传，历史上传的文件不会被自动迁移。如需把老文件也搬到云端，请在存储后台或用第三方工具自行迁移，并把数据库中的对应地址改为新的访问地址。
</div>
<div class="alert <?= $isRemote ? 'ok' : '' ?>">
  当前生效的存储类型：<b><?= e($types[$curType]['name']) ?></b>
  <?= $isRemote ? '——文件将直接写入远程存储，本地仅保留处理过程中的临时文件。' : '——文件保存在本机 uploads 目录。' ?>
  <?php if (!$isRemote && !extension_loaded('curl')): ?>
    <br><b>提示：</b>服务器未启用 cURL 扩展，本地存储不受影响，但各云存储类型将无法上传，建议先启用 cURL。
  <?php endif; ?>
</div>

<div class="box">
  <div class="box-hd"><h2>存储参数</h2><span class="hint">敏感参数加密存储、后台不回显</span></div>
  <form class="form" method="post" action="storage.php" autocomplete="off" id="storageForm">
    <?= wm_csrf_field() ?>

    <div class="fr">
      <label>存储类型</label>
      <div class="fc">
        <select class="inp" name="storage_type" id="storageType" style="max-width:320px">
          <?php foreach ($types as $key => $t): ?>
            <option value="<?= e($key) ?>" data-url="<?= e($t['url']) ?>" <?= $curType === $key ? 'selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="fh" id="storageIntro"><?= e($types[$curType]['intro']) ?></div>
      </div>
    </div>

    <?php foreach ($types as $key => $t):
        $fields = $t['fields'];
        if (!$fields) { continue; } ?>
      <div class="storage-group" data-storage="<?= e($key) ?>" style="<?= $curType === $key ? '' : 'display:none' ?>">
        <?php foreach ($fields as $k => $f):
            $fieldId = 'f_' . $key . '_' . $k;
            $fieldName = 'p_' . $key . '_' . $k;
            $val = $curType === $key ? (string)($cfg[$k] ?? '') : '';
            $isSecret = !empty($f['secret']);
            $saved = $isSecret && is_array($stored) && !empty($stored[$k]); ?>
          <div class="fr">
            <label for="<?= e($fieldId) ?>"><?= e($f['label']) ?><?= !empty($f['req']) ? ' *' : '' ?></label>
            <div class="fc">
              <?php if (($f['type'] ?? '') === 'select'): ?>
                <select class="inp" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" style="max-width:260px">
                  <?php foreach (($f['opts'] ?? []) as $ov => $ol): ?>
                    <option value="<?= e($ov) ?>" <?= $val === $ov ? 'selected' : '' ?>><?= e($ol) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($isSecret): ?>
                <input class="inp" type="password" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" maxlength="300"
                       value="" autocomplete="new-password"
                       placeholder="<?= $saved ? '已设置，留空则不修改' : '请输入' ?>">
              <?php else: ?>
                <input class="inp" type="text" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" maxlength="300"
                       value="<?= e($val) ?>" placeholder="<?= e((string)($f['ph'] ?? '')) ?>">
              <?php endif; ?>
              <?php if (!empty($f['hint'])): ?>
                <div class="fh"><?= e((string)$f['hint']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="fr"><label></label><div class="fc acts">
      <button class="btn" type="submit" name="act" value="save">保存</button>
      <button class="btn ghost" type="submit" name="act" value="test" data-confirm="将使用当前填写的参数上传一个探测文件并尝试删除，确认继续？">连接测试</button>
      <?php $curUrl = $types[$curType]['url']; ?>
      <a class="btn ghost" id="storageLink" href="<?= e($curUrl) ?>" target="_blank" rel="noopener noreferrer"
         style="<?= $curUrl === '' ? 'display:none' : '' ?>">开通地址 / 文档</a>
    </div></div>
  </form>
</div>

<div class="box">
  <div class="box-hd"><h2>各类型说明与开通入口</h2></div>
  <table class="tb">
    <thead><tr><th style="width:150px">类型</th><th>说明</th><th style="width:130px">参数</th><th style="width:120px">入口</th></tr></thead>
    <tbody>
    <?php foreach ($types as $key => $t): ?>
      <tr<?= $curType === $key ? ' style="background:#f4faf6"' : '' ?>>
        <td><?= e($t['name']) ?><?= $curType === $key ? ' <span class="st on">当前</span>' : '' ?></td>
        <td><span class="hint"><?= e(wm_cut($t['intro'], 110)) ?></span></td>
        <td><span class="hint"><?= count($t['fields']) ?> 项</span></td>
        <td>
          <?php if ($t['url'] !== ''): ?>
            <a href="<?= e($t['url']) ?>" target="_blank" rel="noopener noreferrer"><?= strpos($t['url'], 'doc') !== false || strpos($t['url'], 'docs') !== false || strpos($t['url'], 'developer') !== false ? '文档' : '开通' ?></a>
          <?php else: ?><span class="hint">—</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="fh" style="margin-top:10px">
    提示：对象存储（COS / OSS / OBS / S3 等）需要先在对应云厂商处开通服务、创建存储桶并配置访问权限（一般设为「公共读」或绑定自定义域名 + CDN），再回到本页填写参数并点「连接测试」验证。
    WebDAV 与 OpenList 属于自建 / 私有服务，请确认服务器能出网访问该地址（这类地址常部署在内网，属于管理员显式配置的可信目标，不做内网拦截）。
  </div>
</div>

<script>
(function () {
  var sel = document.getElementById('storageType');
  var intro = document.getElementById('storageIntro');
  // 注意：array_map 处理多数组会重排下标，这里显式建「类型 => 说明」映射
  var intros = <?= ejs(array_combine(
      array_keys($types),
      array_map(static function ($t) { return (string)$t['intro']; }, $types)
  )) ?>;
  if (!sel) { return; }

  function apply() {
    var type = sel.value;
    var groups = document.querySelectorAll('.storage-group');
    for (var i = 0; i < groups.length; i++) {
      groups[i].style.display = groups[i].getAttribute('data-storage') === type ? '' : 'none';
    }
    if (intro && intros[type] !== undefined) { intro.textContent = intros[type]; }
    var cur = sel.options[sel.selectedIndex];
    var link = document.getElementById('storageLink');
    if (link && cur) {
      var url = cur.getAttribute('data-url') || '';
      link.href = url;
      link.style.display = url === '' ? 'none' : '';
    }
  }

  sel.addEventListener('change', apply);
  apply();
}());
</script>
<?php wm_foot();