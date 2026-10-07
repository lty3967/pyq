<?php
/**
 * 前台 API：点赞、评论、浏览上报、音乐信息获取
 */
declare(strict_types=1);
require __DIR__ . '/includes/init.php';
require WM_INC . '/model.php';
// 必须用 require_once：model.php 里已经 require_once 过 music.php，
// 这里再用普通 require 会把文件再执行一遍，触发
// "Cannot redeclare wm_music_platforms()" 致命错误。
// 致命错误在生产环境（display_errors=Off）不输出任何内容，
// 前端 JSON.parse 失败只能提示「服务器响应异常」，且点赞/评论/浏览全部失效。
require_once WM_INC . '/music.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

wm_require_post(true);
wm_csrf_check(true);

$act = wm_input('act');
$postId = wm_input_int('post_id');

// IP 黑名单
if ($act !== 'view') {
    $blocked = wm_blocked_ips();
    if ($blocked && in_array(wm_client_ip(), $blocked, true)) {
        wm_json(false, '当前网络环境已被限制互动', [], 403);
    }
}

// ---------------- 音乐信息获取（与动态无关，单独提前处理） ----------------
if ($act === 'music') {
    if (!wm_rate_limit('music_fetch', 30, 600)) {
        wm_json(false, '获取过于频繁，请稍后再试', [], 429);
    }
    $platform = wm_input('platform');
    $input = wm_input('input');
    $res = wm_music_fetch($platform, $input);
    if (!$res['ok']) {
        wm_json(false, $res['msg'], [], 200);
    }
    wm_json(true, '获取成功', $res['data']);
}

if ($postId <= 0) {
    wm_json(false, '参数错误', [], 400);
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
        // 登录用户认账号，同时可接管自己在同一 IP 上的历史匿名记录；
        // 绝不能匹配 user_id <> 0 的他人记录，否则会误删同一出口 IP 下别人的赞。
        $exists = $currentUserId > 0
            ? wm_one('SELECT id FROM ' . wm_t('like') . '
                      WHERE post_id = ? AND (user_id = ? OR (ip_hash = ? AND user_id = 0))
                      ORDER BY id LIMIT 1', [$postId, $currentUserId, $ipHash])
            : wm_one('SELECT id FROM ' . wm_t('like') . '
                      WHERE post_id = ? AND ip_hash = ? AND user_id = 0 LIMIT 1', [$postId, $ipHash]);
        // 预置初值：catch 分支里 wm_json 会 exit，但静态分析识别不到，
        // 没有初值会一直报「可能未定义变量」
        $liked = false;
        if ($exists !== null) {
            wm_exec('DELETE FROM ' . wm_t('like') . ' WHERE id = ?', [(int)$exists['id']]);
            $liked = false;
        } else {
            try {
                wm_exec('INSERT INTO ' . wm_t('like') . ' (post_id, user_id, ip_hash, created_at) VALUES (?, ?, ?, NOW())', [$postId, $currentUserId, $ipHash]);
                $liked = true;
            } catch (Throwable $e) {
                // 唯一键冲突：匿名访客在同一出口 IP（NAT/公司网络）下已有记录，
                // 或该账号已点过赞。此处禁止删除他人记录，仅如实告知。
                wm_json(false, $currentUserId > 0 ? '你已经赞过这条内容了' : '当前网络已有其他访客点过赞', [], 409);
            }
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

        $nickname = wm_input('nickname');
        $email = wm_input('email');
        if ($currentUserId > 0) {
            $identity = wm_one('SELECT nickname,email,username FROM ' . wm_t('user') . ' WHERE id=? AND status=1 LIMIT 1', [$currentUserId]);
            if ($identity === null) { wm_json(false, '用户账号不可用', [], 403); }
            $nickname = (string)$identity['nickname'];
            $email = (string)$identity['email'];
            // 历史昵称可能含尖括号或链接。此处剥离而非直接拒绝，
            // 否则这类账号会永远卡在「昵称包含非法字符」而无法评论。
            $clean = trim(str_replace(['<', '>'], '', preg_replace('#https?://#i', '', $nickname) ?? $nickname));
            $nickname = $clean !== '' ? $clean : (string)$identity['username'];
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

        // 频率限制放在全部字段校验之后，避免非法请求白白消耗额度。
        // 登录用户按账号、匿名访客按 IP，与「个人中心回复」保持同一套桶。
        $rateSubject = $currentUserId > 0 ? 'user' . $currentUserId : '';
        $interval = max(0, (int)wm_setting('comment_interval', '30'));
        $maxHour = max(1, (int)wm_setting('comment_max_per_hour', '10'));
        if ($interval > 0 && !wm_rate_limit('cmt_i', 1, $interval, $rateSubject)) {
            wm_json(false, '发言过快，请' . $interval . '秒后再试', [], 429);
        }
        if (!wm_rate_limit('cmt_h', $maxHour, 3600, $rateSubject)) {
            wm_json(false, '评论次数已达上限，请稍后再试', [], 429);
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
        // 浏览上报虽按 IP+日去重，但仍会写库；无节流可被脚本灌爆 view 表
        if (!wm_rate_limit('view', 120, 300)) {
            wm_json(false, '请求过于频繁', [], 429);
        }
        wm_post_view($postId);
        wm_json(true, 'ok');
        break;

    default:
        wm_json(false, '未知操作', [], 400);
}
