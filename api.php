<?php
/**
 * 前台 API：点赞、评论、浏览上报
 */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';
require WM_INC . '/model.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

wm_require_post(true);
wm_csrf_check(true);

$act = wm_input('act');
$postId = wm_input_int('post_id');

if ($postId <= 0) {
    wm_json(false, '参数错误', [], 400);
}

// IP 黑名单
if ($act !== 'view') {
    $blocked = array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)wm_setting('block_ips', '')) ?: []));
    if ($blocked && in_array(wm_client_ip(), $blocked, true)) {
        wm_json(false, '当前网络环境已被限制互动', [], 403);
    }
}
$post = wm_one('SELECT id, status, allow_comment, likes, comments FROM ' . wm_t('post') . ' WHERE id = ? LIMIT 1', [$postId]);
$currentUser = wm_user();
$currentUserId = $currentUser !== null ? (int)$currentUser['id'] : 0;
if ($post === null || (int)$post['status'] !== 1) {
    wm_json(false, '内容不存在或已下架', [], 404);
}

switch ($act) {

    // ---------------- 点赞 / 取消 ----------------
    case 'like':
        if ((string)wm_setting('allow_like', '1') !== '1') {
            wm_json(false, '点赞功能已关闭');
        }
        if (!wm_rate_limit('like', 40, 300)) {
            wm_json(false, '操作过于频繁，请稍后再试', [], 429);
        }
        $ipHash = wm_ip_hash();
        $exists = $currentUserId > 0
            ? wm_one('SELECT id FROM ' . wm_t('like') . ' WHERE post_id = ? AND user_id = ? LIMIT 1', [$postId, $currentUserId])
            : wm_one('SELECT id FROM ' . wm_t('like') . ' WHERE post_id = ? AND ip_hash = ? LIMIT 1', [$postId, $ipHash]);
        if ($exists !== null) {
            wm_exec('DELETE FROM ' . wm_t('like') . ' WHERE id = ?', [(int)$exists['id']]);
            $liked = false;
        } else {
            try {
                wm_exec('INSERT INTO ' . wm_t('like') . ' (post_id, user_id, ip_hash, created_at) VALUES (?, ?, ?, NOW())', [$postId, $currentUserId, $ipHash]);
            } catch (Throwable $e) {
                // 唯一键冲突视为已点赞
            }
            $liked = true;
        }
        $count = (int)wm_value('SELECT COUNT(*) FROM ' . wm_t('like') . ' WHERE post_id = ?', [$postId]);
        wm_exec('UPDATE ' . wm_t('post') . ' SET likes = ? WHERE id = ?', [$count, $postId]);
        wm_json(true, $liked ? '已赞' : '已取消', ['liked' => $liked, 'likes' => $count]);
        break;

    // ---------------- 评论 ----------------
    case 'comment':
        if ((string)wm_setting('allow_comment', '1') !== '1') {
            wm_json(false, '评论功能已关闭');
        }
        if ((int)$post['allow_comment'] !== 1) {
            wm_json(false, '该内容已关闭评论');
        }

        $interval = max(0, (int)wm_setting('comment_interval', '30'));
        $maxHour = max(1, (int)wm_setting('comment_max_per_hour', '10'));
        if ($interval > 0 && !wm_rate_limit('cmt_i', 1, $interval)) {
            wm_json(false, '发言过快，请' . $interval . '秒后再试', [], 429);
        }
        if (!wm_rate_limit('cmt_h', $maxHour, 3600)) {
            wm_json(false, '评论次数已达上限，请稍后再试', [], 429);
        }

        $nickname = wm_input('nickname');
        $email = wm_input('email');
        if ($currentUserId > 0) {
            $identity = wm_one('SELECT nickname,email FROM ' . wm_t('user') . ' WHERE id=? AND status=1 LIMIT 1', [$currentUserId]);
            if ($identity === null) { wm_json(false, '用户账号不可用', [], 403); }
            $nickname = (string)$identity['nickname'];
            $email = (string)$identity['email'];
        }
        $content = wm_input('content');
        $parentId = wm_input_int('parent_id');

        if (mb_strlen($nickname) < 1 || mb_strlen($nickname) > 20) {
            wm_json(false, '昵称长度需为 1-20 字');
        }
        if (preg_match('#https?://|<|>#i', $nickname)) {
            wm_json(false, '昵称包含非法字符');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100)) {
            wm_json(false, '邮箱格式不正确');
        }
        $len = mb_strlen($content);
        if ($len < 1 || $len > 500) {
            wm_json(false, '评论内容需为 1-500 字');
        }
        // 链接数量限制，抑制垃圾广告
        if (preg_match_all('#https?://#i', $content) > 2) {
            wm_json(false, '评论中链接过多');
        }

        if ($parentId > 0) {
            $parent = wm_one('SELECT id FROM ' . wm_t('comment') . ' WHERE id = ? AND post_id = ? AND status = 1 LIMIT 1', [$parentId, $postId]);
            if ($parent === null) { $parentId = 0; }
        }

        // 敏感词
        $hits = array_merge(wm_badword_hit($content), wm_badword_hit($nickname));
        $mode = (string)wm_setting('comment_mask_mode', 'reject');
        if ($hits) {
            if ($mode === 'reject') {
                wm_json(false, '内容包含违禁词，发布失败');
            }
            if ($mode === 'mask') {
                $content = wm_badword_mask($content);
                $nickname = wm_badword_mask($nickname);
            }
        }

        $needAudit = (string)wm_setting('comment_need_audit', '1') === '1';
        $status = $needAudit ? 0 : 1;
        if ($hits && $mode === 'audit') { $status = 0; }

        wm_exec('INSERT INTO ' . wm_t('comment') . ' (post_id, user_id, parent_id, nickname, email, content, status, ip, ip_hash, ua, bad_hit, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())', [
            $postId, $currentUserId, $parentId, $nickname, $email, $content, $status,
            wm_client_ip(), wm_ip_hash(),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            mb_substr(implode(',', $hits), 0, 200),
        ]);
        $cid = wm_insert_id();
        wm_post_resync($postId);

        // 邮件通知
        if ((string)wm_setting('notify_on_comment', '0') === '1') {
            $to = (string)wm_setting('notify_email', '');
            if ($to !== '') {
                try {
                    require_once WM_INC . '/mailer.php';
                    $body = wm_mail_template('收到新评论', '<p><b>' . e($nickname) . '</b> 评论了动态 #' . $postId . '</p>'
                        . '<blockquote style="border-left:3px solid #07c160;padding-left:12px;color:#555">' . wm_text_html($content) . '</blockquote>'
                        . '<p style="color:#999;font-size:13px">状态：' . ($status === 1 ? '已显示' : '待审核') . '</p>');
                    (new WmMailer())->send($to, '【' . wm_setting('site_name', '朋友圈') . '】收到新评论', $body);
                } catch (Throwable $e) {
                    error_log('notify mail fail: ' . $e->getMessage());
                }
            }
        }

        $data = ['id' => $cid, 'status' => $status, 'comments' => (int)wm_value('SELECT comments FROM ' . wm_t('post') . ' WHERE id = ?', [$postId])];
        if ($status === 1) {
            $data['html_nickname'] = e($nickname);
            $data['html_content'] = wm_text_html($content);
        }
        wm_json(true, $status === 1 ? '评论成功' : '评论已提交，等待审核', $data);
        break;

    // ---------------- 浏览上报 ----------------
    case 'view':
        wm_post_view($postId);
        wm_json(true, 'ok');
        break;

    default:
        wm_json(false, '未知操作', [], 400);
}
