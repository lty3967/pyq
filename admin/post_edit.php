<?php
/**
 * 发布 / 编辑朋友圈
 */
declare(strict_types=1);
require __DIR__ . '/inc/layout.php';
require_once WM_INC . '/upload.php';
$admin = wm_require_admin();

$id = wm_input_int('id', 'GET', 0);
$post = null;
$mediaJson = '[]';

if ($id > 0) {
    $post = wm_post_get($id, true);
    if ($post === null) {
        wm_flash(false, '内容不存在');
        wm_redirect('posts.php');
    }
    $mj = [];
    foreach ($post['media'] as $m) {
        $mj[] = [
            'type' => (string)$m['type'], 'path' => (string)$m['path'], 'thumb' => (string)$m['thumb'],
            'width' => (int)$m['width'], 'height' => (int)$m['height'], 'size' => (int)$m['size'],
        ];
    }
    $mediaJson = json_encode($mj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
}

$cats = wm_categories(false);
$errors = [];
$musicReady = wm_music_ready();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    wm_csrf_check();

    $content = wm_input('content');
    $catId = wm_input_int('cat_id');
    $location = wm_input('location');
    $status = wm_input_int('status') === 1 ? 1 : 0;
    $isTop = wm_input_int('is_top') === 1 ? 1 : 0;
    $allowCmt = wm_input_int('allow_comment') === 1 ? 1 : 0;
    $mediaRaw = (string)($_POST['media_data'] ?? '[]');

    // ---------- 分享音乐（与图文/视频互斥） ----------
    $musicId = 0;
    $musicRow = null;
    $musicRaw = json_decode((string)($_POST['music_data'] ?? ''), true);
    if (is_array($musicRaw) && trim((string)($musicRaw['song_name'] ?? '')) !== '') {
        if (!$musicReady) {
            $errors[] = '数据库结构未升级，无法保存音乐，请先执行根目录 upgrade.sql';
        } else {
            // song_id 缺失（手工填写）时 wm_music_pack 会用「平台+歌名+歌手」
            // 派生一个稳定的伪 ID，重复分享同一首歌会自动复用同一条记录
            $musicRow = wm_music_pack($musicRaw);
            if ($musicRow['song_name'] === '') {
                $errors[] = '请填写歌曲名';
            }
        }
    }

    // 解析并逐项校验媒体（路径必须已存在于 uploads 内，防伪造）
    $media = [];
    $decoded = json_decode($mediaRaw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $m) {
            if (!is_array($m)) { continue; }
            $mType = ($m['type'] ?? '') === 'video' ? 'video' : 'image';
            // 路径白名单 + realpath 越界校验统一交给公共函数
            $mPath = wm_safe_media_path((string)($m['path'] ?? ''));
            if ($mPath === '') { continue; }
            $media[] = [
                'type' => $mType,
                'path' => $mPath,
                'thumb' => wm_safe_thumb_path((string)($m['thumb'] ?? '')),
                'width' => max(0, min(100000, (int)($m['width'] ?? 0))),
                'height' => max(0, min(100000, (int)($m['height'] ?? 0))),
                'size' => (int)@filesize(WM_ROOT . '/' . $mPath),
            ];
            if (count($media) >= 9) { break; }
        }
    }
    // 视频与图片不混排，视频仅 1 个（与前台用户端共用同一规则）
    $media = wm_media_pick($media, 9);
    // 选择分享音乐时不允许同时发布图文/视频
    if ($musicRow !== null) {
        $media = [];
    }

    if ($content === '' && !$media && $musicRow === null) {
        $errors[] = '请填写文字内容，或上传图片/视频，或分享一首歌';
    }
    if (mb_strlen($content) > 5000) {
        $errors[] = '文字内容不能超过 5000 字';
    }
    if (mb_strlen($location) > 50) {
        $errors[] = '位置信息不能超过 50 字';
    }
    if ($catId > 0) {
        $ok = wm_value('SELECT id FROM ' . wm_t('category') . ' WHERE id = ? LIMIT 1', [$catId]);
        if ($ok === null) { $catId = 0; }
    }

    if (!$errors) {
        $mediaType = 'none';
        if ($musicRow !== null) {
            $mediaType = 'music';
            $musicId = wm_music_save($musicRow, (int)$admin['id']);
            if ($musicId <= 0) { $errors[] = '音乐信息保存失败，请重试'; }
        } elseif ($media) {
            $mediaType = $media[0]['type'] === 'video' ? 'video' : 'image';
        }
    }

    if (!$errors) {
        $postId = 0;
        $removedMedia = [];
        // 媒体是「先全删再重建」，重建时必须带上原动态的归属用户，
        // 否则用户动态被管理员编辑后 media.user_id 会被清零，
        // 用户再编辑时按 user_id 就查不到自己的图（看不见也删不掉）
        $ownerId = $id > 0 ? (int)($post['user_id'] ?? 0) : 0;
        $pdo = wm_db();
        try {
            $pdo->beginTransaction();
            // 列名与占位符全部由数组生成：早先用字符串拼接 + 条件片段拼 SQL，
            // 漏掉一个逗号就是整条语句 1064，而错误日志里看不到真实语句，
            // 极难定位。改成拼装后结构上不可能再少逗号。
            $setCols = [
                'cat_id' => '?', 'content' => '?', 'media_type' => '?', 'location' => '?',
                'status' => '?', 'is_top' => '?', 'allow_comment' => '?',
            ];
            $setVals = [$catId, $content, $mediaType, $location, $status, $isTop, $allowCmt];
            if ($musicReady) {
                $setCols['music_id'] = '?';
                $setVals[] = $musicId;
            }

            if ($id > 0) {
                wm_exec(wm_build_update(wm_t('post'), $setCols) . ' WHERE id = ?',
                    array_merge($setVals, [$id]));
                $postId = $id;

                // 先收集被移除的媒体，物理文件等事务提交成功后再删
                $oldRows = wm_all('SELECT * FROM ' . wm_t('media') . ' WHERE post_id = ?', [$postId]);
                $newPaths = array_column($media, 'path');
                foreach ($oldRows as $o) {
                    if (!in_array((string)$o['path'], $newPaths, true)) {
                        $removedMedia[] = $o;
                    }
                }
                wm_exec('DELETE FROM ' . wm_t('media') . ' WHERE post_id = ?', [$postId]);
            } else {
                $insCols = ['admin_id', 'cat_id', 'content', 'media_type', 'location',
                    'status', 'is_top', 'allow_comment', 'created_at'];
                $insRaw = ['created_at' => 'NOW()'];
                $insVals = [(int)$admin['id'], $catId, $content, $mediaType, $location, $status, $isTop, $allowCmt];
                if ($musicReady) {
                    $insCols[] = 'music_id';
                    $insVals[] = $musicId;
                }
                wm_exec(wm_build_insert(wm_t('post'), $insCols, $insRaw), $insVals);
                $postId = wm_insert_id();
            }

            foreach ($media as $i => $m) {
                wm_exec('INSERT INTO ' . wm_t('media') . ' (post_id, user_id, type, path, thumb, width, height, size, sort, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [$postId, $ownerId, $m['type'], $m['path'], $m['thumb'], $m['width'], $m['height'], $m['size'], $i]);
            }
            $pdo->commit();

            // 事务提交成功后再删除物理文件，避免回滚造成「记录还在、文件已丢」
            foreach ($removedMedia as $o) {
                wm_media_unlink((string)$o['path']);
                if ((string)$o['thumb'] !== '') { wm_media_unlink((string)$o['thumb']); }
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            // 必须带上真实 SQL：PDO 的报错只说「语法错误附近是什么」，
            // 不给原句的话只能靠猜
            error_log('post save fail: ' . $ex->getMessage() . ' | SQL: ' . wm_last_sql());
            $errors[] = '保存失败，请重试';
        }

        if (!$errors) {
            wm_log($id > 0 ? '编辑动态' : '发布动态', 'ID：' . $postId);
            wm_flash(true, $id > 0 ? '内容已更新' : '发布成功');
            wm_redirect('posts.php');
        }
    }

    // 回填
    $post = [
        'id' => $id, 'content' => $content, 'cat_id' => $catId, 'location' => $location,
        'status' => $status, 'is_top' => $isTop, 'allow_comment' => $allowCmt,
    ];
    $mediaJson = json_encode($media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
}

$maxImgMb = (int)wm_setting('max_image_mb', '10');
$maxVidMb = (int)wm_setting('max_video_mb', '40');
$musicInit = null;
if ($id > 0 && !empty($post['music'])) {
    $musicInit = $post['music'];
} elseif (is_array($musicRow ?? null)) {
    $musicInit = $musicRow;
}

wm_head($id > 0 ? '编辑朋友圈' : '发布朋友圈');
?>
<?php if ($errors): ?>
  <div class="alert err"><?php foreach ($errors as $er) { echo '<p>' . e($er) . '</p>'; } ?></div>
<?php endif; ?>

<div class="box">
  <form class="form" method="post" action="post_edit.php<?= $id > 0 ? '?id=' . $id : '' ?>" id="postForm">
    <?= wm_csrf_field() ?>

    <div class="fr">
      <label for="content">文字内容</label>
      <div class="fc">
        <textarea class="inp" id="content" name="content" rows="6" maxlength="5000"
                  data-counter="cCount" placeholder="这一刻的想法…"><?= e((string)($post['content'] ?? '')) ?></textarea>
        <div class="fh"><span id="cCount"></span> · 支持换行，网址会自动识别为链接；内容以纯文本存储并转义输出</div>
      </div>
    </div>

    <div class="fr">
      <label>图片 / 视频</label>
      <div class="fc">
        <div class="uploader" id="uploader" data-max-img="9">
          <div class="up-list" id="upList"></div>
          <div class="up-btns">
            <button class="btn sm" type="button" id="btnImg">＋ 添加图片</button>
            <button class="btn sm ghost" type="button" id="btnVid">＋ 添加视频</button>
            <span class="hint" id="upState"></span>
          </div>
          <input type="file" id="fileImg" accept="image/jpeg,image/png,image/gif,image/webp" multiple hidden>
          <input type="file" id="fileVid" accept="video/mp4,video/webm" hidden>
          <div class="up-tip">图片最多 9 张（≤<?= $maxImgMb ?>MB，JPG/PNG/GIF/WEBP，服务端重新编码剥离元数据）；视频 1 个（≤<?= $maxVidMb ?>MB，MP4/WEBM）；图片与视频不可混合。</div>
        </div>
        <input type="hidden" name="media_data" id="mediaData" value="<?= e($mediaJson) ?>">
      </div>
    </div>

    <div class="fr">
      <label for="cat_id">分类</label>
      <div class="fc">
        <select class="inp" id="cat_id" name="cat_id">
          <option value="0">未分类</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)($post['cat_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
              <?= e((string)$c['name']) ?><?= (int)$c['status'] !== 1 ? '（已停用）' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="fh"><a href="categories.php">管理分类 →</a></div>
      </div>
    </div>

    <div class="fr">
      <label>分享音乐</label>
      <div class="fc">
        <div id="musicMount"></div>
        <?php if (!$musicReady): ?>
          <div class="fh" style="color:#e6a23c">数据库结构未升级（缺少 wm_music 表 / post.music_id 字段），请先执行根目录 upgrade.sql 后再分享音乐。</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="fr">
      <label for="location">所在位置</label>
      <div class="fc">
        <input class="inp" type="text" id="location" name="location" maxlength="50"
               value="<?= e((string)($post['location'] ?? '')) ?>" placeholder="选填，如：杭州·西湖">
      </div>
    </div>

    <div class="fr">
      <label>选项</label>
      <div class="fc">
        <label class="ck"><input type="checkbox" name="status" value="1" <?= (int)($post['status'] ?? 1) === 1 ? 'checked' : '' ?>> 立即发布（不勾选为草稿）</label>
        <label class="ck"><input type="checkbox" name="is_top" value="1" <?= (int)($post['is_top'] ?? 0) === 1 ? 'checked' : '' ?>> 置顶</label>
        <label class="ck"><input type="checkbox" name="allow_comment" value="1" <?= (int)($post['allow_comment'] ?? 1) === 1 ? 'checked' : '' ?>> 允许评论</label>
      </div>
    </div>

    <div class="fr">
      <label></label>
      <div class="fc acts">
        <button class="btn" type="submit"><?= $id > 0 ? '保存修改' : '发布' ?></button>
        <a class="btn ghost" href="posts.php">返回列表</a>
        <?php if ($id > 0): ?><a class="btn ghost" href="../index.php" target="_blank" rel="noopener">查看前台</a><?php endif; ?>
      </div>
    </div>
  </form>
</div>
<script src="../assets/js/music.js?v=<?= e(WM_ASSET_VER) ?>"></script>
<script>
(function () {
  if (!window.WmMusic) { return; }
  window.WM_MUSIC_INIT = <?= ejs($musicInit) ?>;
  // 等 DOMContentLoaded 再挂载，确保 admin.js 已经注册好 WmUploader（清空媒体用）
  document.addEventListener('DOMContentLoaded', function () {
    window.WmMusic.mount({
      mount: '#musicMount',
      api: '../api.php',
      mediaBox: '#uploader',
      platforms: <?= ejs(wm_music_platforms()) ?>
    });
  });
}());
</script>
<?php wm_foot(); ?>
