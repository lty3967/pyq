<?php
/**
 * 违禁词与评论策略设置
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
$admin = wm_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act');

    if ($act === 'words') {
        $raw = (string)($_POST['bad_words'] ?? '');
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $raw) ?? '';
        $arr = preg_split('/[\r\n]+/u', $raw) ?: [];
        $clean = [];
        foreach ($arr as $w) {
            $w = trim($w);
            if ($w === '' || mb_strlen($w) > 40) { continue; }
            if (!in_array($w, $clean, true)) { $clean[] = $w; }
            if (count($clean) >= 2000) { break; }
        }
        wm_setting_set('bad_words', implode("\n", $clean));

        $mode = wm_input('comment_mask_mode');
        if (!in_array($mode, ['reject', 'mask', 'audit'], true)) { $mode = 'reject'; }
        wm_setting_set('comment_mask_mode', $mode);
        wm_setting_set('comment_need_audit', wm_input_int('comment_need_audit') === 1 ? '1' : '0');
        wm_setting_set('comment_interval', (string)max(0, min(3600, wm_input_int('comment_interval'))));
        wm_setting_set('comment_max_per_hour', (string)max(1, min(500, wm_input_int('comment_max_per_hour'))));

        wm_log('更新违禁词', '共 ' . count($clean) . ' 个，策略：' . $mode);
        wm_flash(true, '已保存，共 ' . count($clean) . ' 个违禁词');
        wm_redirect('badwords.php');
    }

    if ($act === 'ips') {
        $raw = (string)($_POST['block_ips'] ?? '');
        $arr = preg_split('/[\r\n,]+/', $raw) ?: [];
        $clean = [];
        foreach ($arr as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, $clean, true)) {
                $clean[] = $ip;
            }
            if (count($clean) >= 500) { break; }
        }
        wm_setting_set('block_ips', implode("\n", $clean));
        wm_log('更新 IP 黑名单', '共 ' . count($clean) . ' 个');
        wm_flash(true, '已保存，共 ' . count($clean) . ' 个屏蔽 IP');
        wm_redirect('badwords.php');
    }

    if ($act === 'scan') {
        // 用当前违禁词回溯扫描历史评论
        $words = wm_badwords();
        $hitIds = [];
        if ($words) {
            $rows = wm_all('SELECT id, nickname, content FROM ' . wm_t('comment') . ' WHERE status <> 2 ORDER BY id DESC LIMIT 5000');
            foreach ($rows as $r) {
                $hits = array_merge(wm_badword_hit((string)$r['content']), wm_badword_hit((string)$r['nickname']));
                if ($hits) {
                    $hitIds[] = (int)$r['id'];
                    wm_exec('UPDATE ' . wm_t('comment') . ' SET status = 2, bad_hit = ? WHERE id = ?',
                        [mb_substr(implode(',', array_unique($hits)), 0, 200), (int)$r['id']]);
                }
            }
        }
        if ($hitIds) {
            $posts = array_column(wm_all('SELECT DISTINCT post_id FROM ' . wm_t('comment') . ' WHERE id IN (' . implode(',', array_map('intval', $hitIds)) . ')'), 'post_id');
            foreach ($posts as $pid) { wm_post_resync((int)$pid); }
        }
        wm_log('回溯扫描违禁词', '命中 ' . count($hitIds) . ' 条');
        wm_flash(true, '扫描完成，命中并屏蔽 ' . count($hitIds) . ' 条评论');
        wm_redirect('badwords.php');
    }
}

$words = (string)wm_setting('bad_words', '');
$wordCount = count(wm_badwords());
$ips = (string)wm_setting('block_ips', '');
$ipCount = count(array_filter(array_map('trim', preg_split('/[\r\n]+/', $ips) ?: [])));
$mode = (string)wm_setting('comment_mask_mode', 'reject');

wm_head('违禁词设置');
?>
<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2>违禁词与评论策略</h2><span class="hint">当前 <?= $wordCount ?> 个词</span></div>
    <form class="form" method="post" action="badwords.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="words">

      <div class="fr"><label for="bw">违禁词列表</label><div class="fc">
        <textarea class="inp" id="bw" name="bad_words" rows="12" placeholder="每行一个词"><?= e($words) ?></textarea>
        <div class="fh">每行一个词，最多 2000 个，单词最长 40 字。匹配时忽略大小写与空格，可拦截「违 禁 词」这类拆分绕过。</div>
      </div></div>

      <div class="fr"><label>命中处理方式</label><div class="fc">
        <label class="ck"><input type="radio" name="comment_mask_mode" value="reject" <?= $mode === 'reject' ? 'checked' : '' ?>> 直接拒绝发布</label>
        <label class="ck"><input type="radio" name="comment_mask_mode" value="mask" <?= $mode === 'mask' ? 'checked' : '' ?>> 替换为 ***</label>
        <label class="ck"><input type="radio" name="comment_mask_mode" value="audit" <?= $mode === 'audit' ? 'checked' : '' ?>> 强制转为待审核</label>
      </div></div>

      <div class="fr"><label>评论审核</label><div class="fc">
        <label class="ck"><input type="checkbox" name="comment_need_audit" value="1" <?= (string)wm_setting('comment_need_audit', '1') === '1' ? 'checked' : '' ?>> 所有新评论需人工审核后显示</label>
      </div></div>

      <div class="fr"><label>发言频率</label><div class="fc frow">
        <input class="inp" type="number" name="comment_interval" min="0" max="3600" value="<?= (int)wm_setting('comment_interval', '30') ?>" style="max-width:120px">
        <span style="line-height:38px;color:#909399">秒内仅可评论一次，每小时最多</span>
        <input class="inp" type="number" name="comment_max_per_hour" min="1" max="500" value="<?= (int)wm_setting('comment_max_per_hour', '10') ?>" style="max-width:110px">
        <span style="line-height:38px;color:#909399">条</span>
      </div></div>

      <div class="fr"><label></label><div class="fc acts">
        <button class="btn" type="submit">保存设置</button>
      </div></div>
    </form>

    <form method="post" action="badwords.php" style="border-top:1px solid #f0f0f0;padding-top:14px;margin-top:6px">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="scan">
      <button class="btn ghost" type="submit" data-confirm="将用当前违禁词回溯扫描最近 5000 条评论，命中的会被屏蔽，确认继续？">回溯扫描历史评论</button>
      <div class="fh">用当前词库检查已有评论，命中即置为「已屏蔽」。</div>
    </form>
  </section>

  <section class="box">
    <div class="box-hd"><h2>IP 黑名单</h2><span class="hint">当前 <?= $ipCount ?> 个</span></div>
    <form class="form" method="post" action="badwords.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="ips">
      <div class="fr"><label for="bi">屏蔽 IP</label><div class="fc">
        <textarea class="inp" id="bi" name="block_ips" rows="12" placeholder="每行一个 IP"><?= e($ips) ?></textarea>
        <div class="fh">每行一个 IPv4 / IPv6 地址，最多 500 个。名单内 IP 无法提交评论与点赞。可在评论管理中批量加入。</div>
      </div></div>
      <div class="fr"><label></label><div class="fc acts"><button class="btn" type="submit">保存黑名单</button></div></div>
    </form>
  </section>
</div>
<?php wm_foot();
