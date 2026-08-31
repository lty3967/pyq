<?php
/**
 * 操作日志
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
$admin = wm_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    if (wm_input('act') === 'clear') {
        $n = wm_exec('DELETE FROM ' . wm_t('log') . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        wm_log('清理操作日志', '删除 ' . $n . ' 条');
        wm_flash(true, '已清理 ' . $n . ' 条一天前的日志');
        wm_redirect('logs.php');
    }
}

$page = max(1, wm_input_int('page', 'GET', 1));
$size = 30;
$kw = mb_substr(wm_input('kw', 'GET'), 0, 50);

$where = '';
$params = [];
if ($kw !== '') {
    $where = ' WHERE (l.action LIKE :kw OR l.detail LIKE :kw OR l.ip = :ipx)';
    $params[':kw'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $kw) . '%';
    $params[':ipx'] = $kw;
}
$total = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('log') . ' l' . $where, $params);
$offset = ($page - 1) * $size;
$rows = wm_all('SELECT l.*, a.username FROM ' . wm_t('log') . ' l
                LEFT JOIN ' . wm_t('admin') . ' a ON a.id = l.admin_id
                ' . $where . ' ORDER BY l.id DESC LIMIT ' . $size . ' OFFSET ' . $offset, $params);

wm_head('操作日志');
?>
<form class="toolbar" method="get" action="logs.php">
  <input class="inp" type="text" name="kw" value="<?= e($kw) ?>" placeholder="搜索操作 / 详情 / IP" maxlength="50">
  <button class="btn sm" type="submit">搜索</button>
  <?php if ($kw !== ''): ?><a class="btn sm ghost" href="logs.php">清除筛选</a><?php endif; ?>
  <span class="sp"></span>
  <span class="hint">共 <?= $total ?> 条</span>
</form>

<div class="box">
  <table class="tb">
    <thead><tr>
      <th style="width:56px">ID</th><th style="width:100px">操作者</th><th style="width:120px">操作</th>
      <th>详情</th><th style="width:120px">IP</th><th style="width:140px">时间</th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="none">暂无日志</td></tr><?php endif; ?>
    <?php foreach ($rows as $l): ?>
      <tr>
        <td><?= (int)$l['id'] ?></td>
        <td><?= $l['username'] !== null ? e((string)$l['username']) : '<span class="hint">系统/游客</span>' ?></td>
        <td><?= e((string)$l['action']) ?></td>
        <td><?= e(wm_cut((string)$l['detail'], 70)) ?: '—' ?></td>
        <td><span class="hint"><?= e((string)$l['ip']) ?></span></td>
        <td><span class="hint"><?= e((string)$l['created_at']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="toolbar" style="margin:14px 0 0;box-shadow:none;padding:12px 0 0;border-top:1px solid #f0f0f0">
    <form method="post" action="logs.php" style="margin:0">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="clear">
      <button class="btn sm ghost" type="submit" data-confirm="确认删除一天前的所有操作日志？">清理旧日志</button>
    </form>
    <span class="sp"></span>
    <span class="hint">日志包含登录、内容、审核、配置等关键操作，建议保留一段时间用于安全审计</span>
  </div>
</div>

<?= wm_pager($total, $page, $size, $kw !== '' ? 'kw=' . urlencode($kw) : '') ?>
<?php wm_foot();
