<?php
/**
 * 分类管理
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
$admin = wm_require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();
    $act = wm_input('act');

    if ($act === 'save') {
        $cid = wm_input_int('id');
        $name = wm_input('name');
        $slug = wm_input('slug');
        $color = wm_input('color');
        $sort = wm_input_int('sort');
        $status = wm_input_int('status') === 1 ? 1 : 0;

        $err = '';
        if ($name === '' || mb_strlen($name) > 20) {
            $err = '分类名称长度需为 1-20 字';
        } elseif ($slug !== '' && !preg_match('/^[a-z0-9\-]{1,30}$/', $slug)) {
            $err = '别名只能是小写字母、数字和短横线';
        } elseif ($color !== '' && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $err = '颜色格式需为 #RRGGBB';
        }
        if ($color === '') { $color = '#07c160'; }
        $sort = max(-999, min(999, $sort));

        if ($err !== '') {
            wm_flash(false, $err);
        } else {
            $dup = wm_one('SELECT id FROM ' . wm_t('category') . ' WHERE name = ? AND id <> ? LIMIT 1', [$name, $cid]);
            if ($dup !== null) {
                wm_flash(false, '该分类名称已存在');
            } elseif ($cid > 0) {
                wm_exec('UPDATE ' . wm_t('category') . ' SET name = ?, slug = ?, color = ?, sort = ?, status = ? WHERE id = ?',
                    [$name, $slug, $color, $sort, $status, $cid]);
                wm_log('编辑分类', $name);
                wm_flash(true, '分类已更新');
            } else {
                $cnt = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('category'));
                if ($cnt >= 100) {
                    wm_flash(false, '分类数量已达上限（100）');
                } else {
                    wm_exec('INSERT INTO ' . wm_t('category') . ' (name, slug, color, sort, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
                        [$name, $slug, $color, $sort, $status]);
                    wm_log('新增分类', $name);
                    wm_flash(true, '分类已添加');
                }
            }
        }
        wm_redirect('categories.php');
    }

    if ($act === 'delete') {
        $cid = wm_input_int('id');
        if ($cid > 0) {
            $used = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('post') . ' WHERE cat_id = ?', [$cid]);
            $name = (string)wm_value('SELECT name FROM ' . wm_t('category') . ' WHERE id = ?', [$cid]);
            wm_exec('UPDATE ' . wm_t('post') . ' SET cat_id = 0 WHERE cat_id = ?', [$cid]);
            wm_exec('DELETE FROM ' . wm_t('category') . ' WHERE id = ?', [$cid]);
            wm_log('删除分类', $name . '（关联 ' . $used . ' 条动态转为未分类）');
            wm_flash(true, '分类已删除，' . $used . ' 条动态转为未分类');
        }
        wm_redirect('categories.php');
    }
}

$editId = wm_input_int('edit', 'GET', 0);
$edit = $editId > 0 ? wm_one('SELECT * FROM ' . wm_t('category') . ' WHERE id = ? LIMIT 1', [$editId]) : null;

$cats = wm_categories(false);
$counts = [];
foreach (wm_all('SELECT cat_id, COUNT(*) AS n FROM ' . wm_t('post') . ' GROUP BY cat_id') as $r) {
    $counts[(int)$r['cat_id']] = (int)$r['n'];
}

wm_head('分类管理');
?>
<div class="grid2">
  <section class="box">
    <div class="box-hd"><h2><?= $edit ? '编辑分类' : '新增分类' ?></h2><?php if ($edit): ?><a class="hint" href="categories.php">取消编辑</a><?php endif; ?></div>
    <form class="form" method="post" action="categories.php">
      <?= wm_csrf_field() ?>
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="fr"><label for="name">名称</label><div class="fc">
        <input class="inp" type="text" id="name" name="name" maxlength="20" required value="<?= e((string)($edit['name'] ?? '')) ?>">
      </div></div>
      <div class="fr"><label for="slug">别名</label><div class="fc">
        <input class="inp" type="text" id="slug" name="slug" maxlength="30" value="<?= e((string)($edit['slug'] ?? '')) ?>" placeholder="选填，小写字母/数字/短横线">
      </div></div>
      <div class="fr"><label for="color">颜色</label><div class="fc frow">
        <input class="inp" type="color" id="color" name="color" value="<?= e((string)($edit['color'] ?? '#07c160')) ?>" style="max-width:80px;padding:3px">
        <input class="inp" type="number" name="sort" value="<?= (int)($edit['sort'] ?? 0) ?>" min="-999" max="999" placeholder="排序（小在前）">
      </div></div>
      <div class="fr"><label>状态</label><div class="fc">
        <label class="ck"><input type="checkbox" name="status" value="1" <?= (int)($edit['status'] ?? 1) === 1 ? 'checked' : '' ?>> 启用（前台可见）</label>
      </div></div>
      <div class="fr"><label></label><div class="fc acts">
        <button class="btn" type="submit"><?= $edit ? '保存' : '添加' ?></button>
      </div></div>
    </form>
  </section>

  <section class="box">
    <div class="box-hd"><h2>分类列表</h2><span class="hint">共 <?= count($cats) ?> 个</span></div>
    <table class="tb">
      <thead><tr><th style="width:38px">ID</th><th>名称</th><th style="width:60px">动态</th><th style="width:52px">排序</th><th style="width:66px">状态</th><th style="width:120px">操作</th></tr></thead>
      <tbody>
      <?php if (!$cats): ?><tr><td colspan="6" class="none">暂无分类</td></tr><?php endif; ?>
      <?php foreach ($cats as $c): $cid = (int)$c['id']; ?>
        <tr>
          <td><?= $cid ?></td>
          <td><span style="display:inline-block;width:9px;height:9px;border-radius:2px;background:<?= e((string)$c['color']) ?>;margin-right:6px"></span><?= e((string)$c['name']) ?></td>
          <td><a href="posts.php?cat=<?= $cid ?>"><?= (int)($counts[$cid] ?? 0) ?></a></td>
          <td><?= (int)$c['sort'] ?></td>
          <td><?= (int)$c['status'] === 1 ? '<span class="st on">启用</span>' : '<span class="st off">停用</span>' ?></td>
          <td class="acts">
            <a class="btn sm ghost" href="categories.php?edit=<?= $cid ?>">编辑</a>
            <form method="post" action="categories.php" style="display:inline">
              <?= wm_csrf_field() ?>
              <input type="hidden" name="act" value="delete">
              <input type="hidden" name="id" value="<?= $cid ?>">
              <button class="btn sm danger" type="submit" data-confirm="删除分类「<?= e((string)$c['name']) ?>」？该分类下的动态将变为未分类。">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>
<?php wm_foot();
