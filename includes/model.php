<?php
/**
 * 业务模型层：动态、评论、点赞、统计
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }
require_once WM_INC . '/upload.php';

/** 已发布动态列表 */
function wm_post_list(int $page, int $size, int $catId = 0, bool $adminView = false, array $opt = []): array
{
    $page = max(1, $page);
    $size = min(50, max(1, $size));

    $where = [];
    $params = [];
    if (!$adminView) {
        $where[] = 'p.status = 1';
    } elseif (isset($opt['status']) && $opt['status'] !== '') {
        $where[] = 'p.status = :st';
        $params[':st'] = (int)$opt['status'];
    }
    if ($catId > 0) {
        $where[] = 'p.cat_id = :cid';
        $params[':cid'] = $catId;
    }
    if (!empty($opt['keyword'])) {
        $where[] = 'p.content LIKE :kw';
        $params[':kw'] = '%' . wm_like_escape((string)$opt['keyword']) . '%';
    }
    $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $order = $adminView ? 'p.id DESC' : 'p.is_top DESC, p.id DESC';
    if (isset($opt['user_id'])) {
        $where[] = 'p.user_id = :uid';
        $params[':uid'] = (int)$opt['user_id'];
        $sql = ' WHERE ' . implode(' AND ', $where);
    }
    $total = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('post') . ' p' . $sql, $params);

    // 页码收敛 + 偏移量计算（防止超大 OFFSET）
    $pg = wm_paging($total, $page, $size);
    $page = $pg['page'];
    $offset = $pg['offset'];

    $rows = wm_all(
        'SELECT p.*, c.name AS cat_name, c.color AS cat_color,
                CASE
                    WHEN p.user_id > 0 THEN COALESCE(NULLIF(u.nickname, ""), u.username, "用户")
                    ELSE COALESCE(NULLIF(a.nickname, ""), "站长")
                END AS author,
                CASE
                    WHEN p.user_id > 0 THEN NULLIF(u.avatar, "")
                    ELSE NULLIF(a.avatar, "")
                END AS author_avatar
         FROM ' . wm_t('post') . ' p
         LEFT JOIN ' . wm_t('category') . ' c ON c.id = p.cat_id
         LEFT JOIN ' . wm_t('admin') . ' a ON a.id = p.admin_id
         LEFT JOIN ' . wm_t('user') . ' u ON u.id = p.user_id
         ' . $sql . ' ORDER BY ' . $order . ' LIMIT ' . $size . ' OFFSET ' . $offset,
        $params
    );

    $ids = array_column($rows, 'id');
    $media = wm_media_by_posts($ids);
    $comments = $adminView ? [] : wm_comments_by_posts($ids);
    $mine = wm_my_likes($ids);

    foreach ($rows as &$r) {
        $pid = (int)$r['id'];
        $r['media'] = $media[$pid] ?? [];
        $r['comment_list'] = $comments[$pid] ?? [];
        $r['liked'] = in_array($pid, $mine, true);
    }
    unset($r);

    return ['total' => $total, 'rows' => $rows, 'pages' => $pg['pages'], 'page' => $page];
}

/** 批量取媒体 */
function wm_media_by_posts(array $ids): array
{
    if (!$ids) { return []; }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = wm_all('SELECT * FROM ' . wm_t('media') . ' WHERE post_id IN (' . $in . ') ORDER BY post_id, sort, id', $ids);
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['post_id']][] = $r;
    }
    return $out;
}

/** 批量取已通过评论 */
function wm_comments_by_posts(array $ids, int $limitPer = 50): array
{
    if (!$ids) { return []; }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = wm_all('SELECT id, post_id, parent_id, nickname, content, created_at
                    FROM ' . wm_t('comment') . '
                    WHERE post_id IN (' . $in . ') AND status = 1
                    ORDER BY post_id, id ASC', $ids);
    $byPost = [];
    foreach ($rows as $r) {
        $pid = (int)$r['post_id'];
        if (!isset($byPost[$pid])) { $byPost[$pid] = []; }
        if (count($byPost[$pid]) >= $limitPer) { continue; }
        $byPost[$pid][] = $r;
    }
    // 组装 回复@昵称
    foreach ($byPost as $pid => &$list) {
        $map = [];
        foreach ($list as $c) { $map[(int)$c['id']] = $c['nickname']; }
        foreach ($list as &$c) {
            $c['reply_to'] = ((int)$c['parent_id'] > 0 && isset($map[(int)$c['parent_id']])) ? $map[(int)$c['parent_id']] : '';
        }
        unset($c);
    }
    unset($list);
    return $byPost;
}

/** 当前访客已点赞的动态 ID（判定口径需与 api.php 保持一致） */
function wm_my_likes(array $ids): array
{
    if (!$ids) { return []; }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $uid = wm_user_id();
    if ($uid > 0) {
        // 与 api.php 完全同口径：认账号，并可接管本 IP 上自己的历史匿名记录，
        // 但不匹配 user_id <> 0 的他人记录（同出口 IP 场景）
        array_push($params, $uid, wm_ip_hash());
        $sql = 'SELECT post_id FROM ' . wm_t('like') . '
                WHERE post_id IN (' . $in . ') AND (user_id = ? OR (ip_hash = ? AND user_id = 0))';
    } else {
        // 匿名访客只认本 IP 的匿名记录：不能命中 user_id <> 0 的他人记录，
        // 否则同一出口 IP 下会把别人的赞显示成自己的，进而被误删
        array_push($params, wm_ip_hash());
        $sql = 'SELECT post_id FROM ' . wm_t('like') . '
                WHERE post_id IN (' . $in . ') AND ip_hash = ? AND user_id = 0';
    }
    $rows = wm_all($sql, $params);
    return array_map(static fn($r) => (int)$r['post_id'], $rows);
}

/**
 * 媒体混排规则（用户端与后台共用）
 * - 视频与图片不可混合
 * - 视频仅保留 1 个
 * - 图片最多 $max 张
 * 输入元素需包含 type / path 键，返回规范化后的列表
 */
function wm_media_pick(array $media, int $max = 9): array
{
    $media = array_slice(array_values($media), 0, max(1, $max));
    foreach ($media as $m) {
        if (($m['type'] ?? '') === 'video') {
            return [$m];
        }
    }
    return $media;
}

/** 单条动态 */
function wm_post_get(int $id, bool $adminView = false): ?array
{
    $sql = 'SELECT p.*, c.name AS cat_name, c.color AS cat_color, a.nickname AS author
            FROM ' . wm_t('post') . ' p
            LEFT JOIN ' . wm_t('category') . ' c ON c.id = p.cat_id
            LEFT JOIN ' . wm_t('admin') . ' a ON a.id = p.admin_id
            WHERE p.id = ?' . ($adminView ? '' : ' AND p.status = 1') . ' LIMIT 1';
    $row = wm_one($sql, [$id]);
    if ($row === null) { return null; }
    $row['media'] = wm_all('SELECT * FROM ' . wm_t('media') . ' WHERE post_id = ? ORDER BY sort, id', [$id]);
    return $row;
}

/** 记录浏览（按 IP+天去重） */
function wm_post_view(int $postId): void
{
    try {
        $n = wm_exec('INSERT IGNORE INTO ' . wm_t('view') . ' (post_id, ip_hash, day) VALUES (?, ?, CURDATE())', [$postId, wm_ip_hash()]);
        if ($n > 0) {
            wm_exec('UPDATE ' . wm_t('post') . ' SET views = views + 1 WHERE id = ?', [$postId]);
        }
    } catch (Throwable $e) {
        error_log('view fail: ' . $e->getMessage());
    }
}

/** 分类列表 */
function wm_categories(bool $onlyEnabled = true): array
{
    $sql = 'SELECT * FROM ' . wm_t('category');
    if ($onlyEnabled) { $sql .= ' WHERE status = 1'; }
    $sql .= ' ORDER BY sort ASC, id ASC';
    return wm_all($sql);
}

/** 分类下动态数 */
function wm_category_counts(): array
{
    $rows = wm_all('SELECT cat_id, COUNT(*) AS n FROM ' . wm_t('post') . ' WHERE status = 1 GROUP BY cat_id');
    $out = [];
    foreach ($rows as $r) { $out[(int)$r['cat_id']] = (int)$r['n']; }
    return $out;
}

/** 同步动态的评论/点赞计数 */
function wm_post_resync(int $postId): void
{
    wm_exec('UPDATE ' . wm_t('post') . ' p SET
             p.comments = (SELECT COUNT(*) FROM ' . wm_t('comment') . ' c WHERE c.post_id = p.id AND c.status = 1),
             p.likes = (SELECT COUNT(*) FROM ' . wm_t('like') . ' l WHERE l.post_id = p.id)
             WHERE p.id = ?', [$postId]);
}

/**
 * 清理孤立媒体：用户上传后始终未关联到任何动态（post_id = 0）且已超过指定小时的记录。
 * 这类记录留在 media 表里，后台「清理孤立文件」会因它们「有记录」而永远跳过，
 * 导致上传目录持续增长。
 * @param int $hours 超过多少小时未使用才清理
 * @param int $userId 限定某个用户，0 表示全部
 * @return int 清理的文件数
 */
function wm_media_purge_orphans(int $hours = 24, int $userId = 0, int $limit = 500): int
{
    try {
        $hours = max(1, $hours);
        $limit = max(1, min(2000, $limit));
        $sql = 'SELECT id, path, thumb FROM ' . wm_t('media') . '
                WHERE post_id = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)';
        $params = [$hours];
        if ($userId > 0) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . $limit;

        $rows = wm_all($sql, $params);
        if (!$rows) { return 0; }

        $ids = array_map('intval', array_column($rows, 'id'));
        $in = implode(',', array_fill(0, count($ids), '?'));
        wm_exec('DELETE FROM ' . wm_t('media') . ' WHERE id IN (' . $in . ') AND post_id = 0', $ids);

        foreach ($rows as $r) {
            wm_media_unlink((string)$r['path']);
            if ((string)$r['thumb'] !== '') { wm_media_unlink((string)$r['thumb']); }
        }
        return count($rows);
    } catch (Throwable $e) {
        error_log('purge orphans fail: ' . $e->getMessage());
        return 0;
    }
}

/** 作废上传占用缓存，使下次访问后台时重新统计（删除动态、清理孤立文件后调用） */
function wm_upload_usage_reset(): void
{
    try {
        wm_setting_set('upload_usage_at', '0');
    } catch (Throwable $e) {
        error_log('upload usage reset fail: ' . $e->getMessage());
    }
}

/**
 * 上传目录实际占用（带缓存）。
 * wm_dir_size() 会递归 stat 整个目录，媒体量大时每次进后台首页都要全量扫一遍磁盘，
 * 这里把结果缓存一段时间，避免后台首页被磁盘 IO 拖慢。
 * @return array{image:int,video:int}
 */
function wm_upload_usage(int $ttl = 600): array
{
    $at = (int)wm_setting('upload_usage_at', '0');
    if ($at > 0 && time() - $at < $ttl) {
        $cached = json_decode((string)wm_setting('upload_usage_cache', ''), true);
        if (is_array($cached) && isset($cached['image'], $cached['video'])) {
            return ['image' => (int)$cached['image'], 'video' => (int)$cached['video']];
        }
    }

    $data = [
        'image' => wm_dir_size(WM_UPLOAD . '/image') + wm_dir_size(WM_UPLOAD . '/thumb'),
        'video' => wm_dir_size(WM_UPLOAD . '/video'),
    ];
    try {
        wm_setting_set('upload_usage_cache', (string)json_encode($data));
        wm_setting_set('upload_usage_at', (string)time());
    } catch (Throwable $e) {
        error_log('upload usage cache fail: ' . $e->getMessage());
    }
    return $data;
}

/**
 * 评论状态的统一展示映射（后台、用户中心共用一个来源，避免多处各写一份）。
 * @return array{0:string,1:string} [文案, CSS 类名]
 */
function wm_comment_status(int $status): array
{
    $map = [
        0 => ['待审', 'wait'],
        1 => ['通过', 'on'],
        2 => ['屏蔽', 'off'],
    ];
    return $map[$status] ?? ['未知', 'off'];
}

/**
 * 收集评论及其所有子孙评论的 ID。
 * 删除评论时若只清理一层子回复，三级及更深的回复会残留成孤儿数据。
 */
function wm_comment_descendants(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', array_filter($ids, static fn($v) => (int)$v > 0))));
    if (!$ids) { return []; }

    $all = $ids;
    $found = $ids;
    $depth = 0;
    while ($found && $depth < 20) {
        $in = implode(',', array_fill(0, count($found), '?'));
        $rows = wm_all('SELECT id FROM ' . wm_t('comment') . ' WHERE parent_id IN (' . $in . ')', $found);
        $found = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (!in_array($id, $all, true)) {
                $all[] = $id;
                $found[] = $id;
            }
        }
        $depth++;
    }
    return $all;
}

/** 统计概览 */
function wm_stats(): array
{
    $t = static fn(string $n) => wm_t($n);
    $s = [];
    // 用条件聚合同表多指标，把原来的 15 条独立 COUNT 压到 5 条。
    // 返回的键名与含义保持不变，调用方无需改动。
    $post = wm_one('SELECT COUNT(*) AS total,
                           COALESCE(SUM(status = 1), 0) AS online,
                           COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today,
                           COALESCE(SUM(views), 0) AS views
                    FROM ' . $t('post')) ?? [];
    $s['posts']        = (int)($post['total'] ?? 0);
    $s['posts_online'] = (int)($post['online'] ?? 0);
    $s['posts_today']  = (int)($post['today'] ?? 0);
    $s['views']        = (int)($post['views'] ?? 0);

    $like = wm_one('SELECT COUNT(*) AS total,
                           COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today
                    FROM ' . $t('like')) ?? [];
    $s['likes']       = (int)($like['total'] ?? 0);
    $s['likes_today'] = (int)($like['today'] ?? 0);

    $cmt = wm_one('SELECT COUNT(*) AS total,
                          COALESCE(SUM(status = 1), 0) AS ok,
                          COALESCE(SUM(status = 0), 0) AS wait,
                          COALESCE(SUM(status = 2), 0) AS block
                   FROM ' . $t('comment')) ?? [];
    $s['comments']       = (int)($cmt['total'] ?? 0);
    $s['comments_ok']    = (int)($cmt['ok'] ?? 0);
    $s['comments_wait']  = (int)($cmt['wait'] ?? 0);
    $s['comments_block'] = (int)($cmt['block'] ?? 0);

    $s['categories'] = (int)wm_value('SELECT COUNT(*) FROM ' . $t('category'));

    $media = wm_one("SELECT COALESCE(SUM(type = 'image'), 0) AS images,
                            COALESCE(SUM(type = 'video'), 0) AS videos,
                            COALESCE(SUM(CASE WHEN type = 'image' THEN size ELSE 0 END), 0) AS image_bytes,
                            COALESCE(SUM(CASE WHEN type = 'video' THEN size ELSE 0 END), 0) AS video_bytes
                     FROM " . $t('media')) ?? [];
    $s['images']      = (int)($media['images'] ?? 0);
    $s['videos']      = (int)($media['videos'] ?? 0);
    $s['image_bytes'] = (int)($media['image_bytes'] ?? 0);
    $s['video_bytes'] = (int)($media['video_bytes'] ?? 0);
    $s['media_bytes'] = $s['image_bytes'] + $s['video_bytes'];
    return $s;
}

/** 近 N 天趋势 */
function wm_trend(int $days = 14): array
{
    $days = min(60, max(7, $days));
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} day"));
        $out[$d] = ['day' => $d, 'label' => date('n/j', strtotime($d)), 'posts' => 0, 'comments' => 0, 'likes' => 0];
    }
    $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' day'));
    foreach ([['post', 'posts'], ['comment', 'comments'], ['like', 'likes']] as [$tb, $key]) {
        $rows = wm_all('SELECT DATE(created_at) AS d, COUNT(*) AS n FROM ' . wm_t($tb) . '
                        WHERE created_at >= ? GROUP BY DATE(created_at)', [$from . ' 00:00:00']);
        foreach ($rows as $r) {
            $d = (string)$r['d'];
            if (isset($out[$d])) { $out[$d][$key] = (int)$r['n']; }
        }
    }
    return array_values($out);
}

/** 服务器信息（只读，不暴露敏感路径细节） */
function wm_server_info(): array
{
    $info = [];
    $info['php_version'] = PHP_VERSION;
    $info['os'] = php_uname('s') . ' ' . php_uname('r');
    $info['server'] = $_SERVER['SERVER_SOFTWARE'] ?? '未知';
    $info['time'] = date('Y-m-d H:i:s');
    $info['tz'] = date_default_timezone_get();
    $info['upload_max'] = ini_get('upload_max_filesize') . ' / ' . ini_get('post_max_size');
    $info['memory_limit'] = ini_get('memory_limit');
    try {
        $info['mysql_version'] = (string)wm_value('SELECT VERSION()');
    } catch (Throwable $e) {
        $info['mysql_version'] = '未知';
    }

    // 负载
    $load = '不可用';
    if (function_exists('sys_getloadavg')) {
        $l = sys_getloadavg();
        if (is_array($l)) {
            $load = implode(' / ', array_map(static fn($v) => number_format((float)$v, 2), $l));
        }
    }
    $info['load'] = $load;

    // CPU 核心。open_basedir 限制下不访问 /proc，避免产生 PHP 警告。
    $cores = 0;
    if (!ini_get('open_basedir') && is_readable('/proc/cpuinfo')) {
        $c = (string)@file_get_contents('/proc/cpuinfo');
        $cores = preg_match_all('/^processor\s*:/m', $c);
    }
    $info['cpu_cores'] = $cores > 0 ? $cores : 0;

    // 内存
    $info['mem_total'] = 0; $info['mem_used'] = 0; $info['mem_percent'] = 0;
    if (!ini_get('open_basedir') && is_readable('/proc/meminfo')) {
        $m = (string)@file_get_contents('/proc/meminfo');
        $get = static function (string $k) use ($m): int {
            return preg_match('/^' . $k . ':\s+(\d+) kB/m', $m, $x) ? (int)$x[1] * 1024 : 0;
        };
        $total = $get('MemTotal');
        $avail = $get('MemAvailable');
        if ($total > 0) {
            $info['mem_total'] = $total;
            $info['mem_used'] = $total - $avail;
            $info['mem_percent'] = (int)round(($total - $avail) / $total * 100);
        }
    }

    // 磁盘
    $info['disk_total'] = 0; $info['disk_used'] = 0; $info['disk_percent'] = 0;
    $total = @disk_total_space(WM_ROOT);
    $free = @disk_free_space(WM_ROOT);
    if (is_float($total) && $total > 0 && is_float($free)) {
        $info['disk_total'] = (int)$total;
        $info['disk_used'] = (int)($total - $free);
        $info['disk_percent'] = (int)round(($total - $free) / $total * 100);
    }

    // 运行时长
    $info['uptime'] = '';
    if (!ini_get('open_basedir') && is_readable('/proc/uptime')) {
        $u = (float)strtok((string)@file_get_contents('/proc/uptime'), ' ');
        if ($u > 0) {
            $d = (int)floor($u / 86400);
            $h = (int)floor(fmod($u, 86400) / 3600);
            $mi = (int)floor(fmod($u, 3600) / 60);
            $info['uptime'] = ($d > 0 ? $d . '天' : '') . $h . '小时' . $mi . '分';
        }
    }
    return $info;
}
