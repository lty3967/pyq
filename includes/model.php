<?php
/**
 * 业务模型层：动态、评论、点赞、统计
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

/** 已发布动态列表 */
function wm_post_list(int $page, int $size, int $catId = 0, bool $adminView = false, array $opt = []): array
{
    $page = max(1, $page);
    $size = min(50, max(1, $size));
    $offset = ($page - 1) * $size;

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
        $params[':kw'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$opt['keyword']) . '%';
    }
    $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $order = $adminView ? 'p.id DESC' : 'p.is_top DESC, p.id DESC';
    if (isset($opt['user_id'])) {
        $where[] = 'p.user_id = :uid';
        $params[':uid'] = (int)$opt['user_id'];
        $sql = ' WHERE ' . implode(' AND ', $where);
    }
    $total = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('post') . ' p' . $sql, $params);

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

    return ['total' => $total, 'rows' => $rows, 'pages' => (int)ceil($total / $size), 'page' => $page];
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

/** 当前访客已点赞的动态 ID */
function wm_my_likes(array $ids): array
{
    if (!$ids) { return []; }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $in = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $params[] = wm_ip_hash();
    $rows = wm_all('SELECT post_id FROM ' . wm_t('like') . ' WHERE post_id IN (' . $in . ') AND ip_hash = ?', $params);
    return array_map(static fn($r) => (int)$r['post_id'], $rows);
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

/** 统计概览 */
function wm_stats(): array
{
    $t = static fn(string $n) => wm_t($n);
    $s = [];
    $s['posts']          = (int)wm_value('SELECT COUNT(*) FROM ' . $t('post'));
    $s['posts_online']   = (int)wm_value('SELECT COUNT(*) FROM ' . $t('post') . ' WHERE status = 1');
    $s['posts_today']    = (int)wm_value('SELECT COUNT(*) FROM ' . $t('post') . ' WHERE DATE(created_at) = CURDATE()');
    $s['likes']          = (int)wm_value('SELECT COUNT(*) FROM ' . $t('like'));
    $s['likes_today']    = (int)wm_value('SELECT COUNT(*) FROM ' . $t('like') . ' WHERE DATE(created_at) = CURDATE()');
    $s['comments']       = (int)wm_value('SELECT COUNT(*) FROM ' . $t('comment'));
    $s['comments_ok']    = (int)wm_value('SELECT COUNT(*) FROM ' . $t('comment') . ' WHERE status = 1');
    $s['comments_wait']  = (int)wm_value('SELECT COUNT(*) FROM ' . $t('comment') . ' WHERE status = 0');
    $s['comments_block'] = (int)wm_value('SELECT COUNT(*) FROM ' . $t('comment') . ' WHERE status = 2');
    $s['views']          = (int)wm_value('SELECT COALESCE(SUM(views),0) FROM ' . $t('post'));
    $s['categories']     = (int)wm_value('SELECT COUNT(*) FROM ' . $t('category'));
    $s['images']         = (int)wm_value('SELECT COUNT(*) FROM ' . $t('media') . " WHERE type = 'image'");
    $s['videos']         = (int)wm_value('SELECT COUNT(*) FROM ' . $t('media') . " WHERE type = 'video'");
    $s['image_bytes']    = (int)wm_value('SELECT COALESCE(SUM(size),0) FROM ' . $t('media') . " WHERE type = 'image'");
    $s['video_bytes']    = (int)wm_value('SELECT COALESCE(SUM(size),0) FROM ' . $t('media') . " WHERE type = 'video'");
    $s['media_bytes']    = $s['image_bytes'] + $s['video_bytes'];
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
