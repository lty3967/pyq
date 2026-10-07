<?php
/**
 * 分享音乐：多平台歌曲信息抓取 + 落库
 *
 * 设计要点：
 *   - 每个平台一个「解析 + 抓取」适配器，统一输出同一结构：
 *     platform / song_id / song_name / artist / album / cover / url / audio。
 *   - 第三方开放接口随时可能变更或加签名，因此全部按「尽力而为」处理：
 *     抓取成功则自动回填，抓取失败由发布页手工填写，发布本身不依赖抓取成功。
 *   - 出网请求一律先过 wm_music_safe_url()：只允许 http(s)，且域名解析出的
 *     IP 不能落在内网 / 回环 / 保留段，防止发布者填一个链接就把本站当 SSRF 跳板
 *     去读内网服务（redis / 云元数据 169.254.169.254 等）。
 *   - 重定向手动跟随（最多 3 跳，每跳重新做一次 URL 安全校验），不用
 *     CURLOPT_FOLLOWLOCATION，否则跳到内网地址就绕过了上面的校验。
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

define('WM_MUSIC_UA', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
define('WM_MUSIC_MAX_HOPS', 3);

/**
 * 本次请求的出网总时间预算（秒）
 *
 * 主站 PHP 执行上限常见为 30s，而「跟随重定向 3 跳 × 每跳 12s 超时」最坏能到
 * 40s 以上，一旦撞上执行上限，PHP 输出的是 HTML 错误页而不是 JSON，
 * 前端只能报「服务器响应异常」。这里给所有出网请求设一个总预算，
 * 预算耗尽就返回可读的 JSON 错误，并交回手工填写流程。
 */
function wm_music_budget(float $seconds): void
{
    $GLOBALS['wm_music_deadline'] = microtime(true) + max(1.0, $seconds);
}

/** 剩余出网时间（秒）；未设置预算时给一个保守默认值 */
function wm_music_budget_left(): float
{
    $deadline = isset($GLOBALS['wm_music_deadline']) ? (float)$GLOBALS['wm_music_deadline'] : 0.0;
    if ($deadline <= 0.0) {
        return 8.0;
    }
    return $deadline - microtime(true);
}

/**
 * 支持的平台清单
 * @return array<string,array{name:string,color:string,ph:string}>
 */
function wm_music_platforms(): array
{
    return [
        'netease' => ['name' => '网易云音乐', 'color' => '#c20c0c', 'ph' => '歌曲 ID，或 music.163.com 歌曲链接'],
        'qq'      => ['name' => 'QQ音乐', 'color' => '#31c27c', 'ph' => '歌曲 ID（songmid），或 y.qq.com 歌曲链接'],
        'kugou'   => ['name' => '酷狗音乐', 'color' => '#2ca2f9', 'ph' => '歌曲 hash，或 kugou.com 歌曲链接'],
        'kuwo'    => ['name' => '酷我音乐', 'color' => '#ff8f26', 'ph' => '歌曲 ID（rid），或 kuwo.cn 歌曲链接'],
        'apple'   => ['name' => 'Apple Music', 'color' => '#fa243c', 'ph' => '歌曲 ID，或 music.apple.com 歌曲链接'],
        'spotify' => ['name' => 'Spotify', 'color' => '#1db954', 'ph' => '歌曲链接（open.spotify.com/track/...）'],
        'link'    => ['name' => '其他音乐链接', 'color' => '#576b95', 'ph' => '音乐页面链接，尝试读取页面信息'],
    ];
}

/**
 * 音乐表是否已就绪（未执行 upgrade.sql 时降级，不让整站 500）
 * 缺少 wm_music 表或 post.music_id 字段时返回 false：
 * 调用方据此跳过音乐相关读写，站点其余功能照常工作。
 */
function wm_music_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $ready = false;
    try {
        $pdo = wm_db();
        $st = $pdo->query('SHOW COLUMNS FROM ' . wm_t('post') . " LIKE 'music_id'");
        if ($st === false || $st->fetch() === false) {
            return false;
        }
        $pdo->query('SELECT 1 FROM ' . wm_t('music') . ' LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        error_log('music schema check fail: ' . $e->getMessage());
    }
    return $ready;
}

/** 仅允许公网 http(s) 地址：解析 IP 落在内网/回环/保留段时返回空串 */
function wm_music_safe_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 1000) {
        return '';
    }
    $p = parse_url($url);
    if (!is_array($p)) {
        return '';
    }
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    $host = trim((string)($p['host'] ?? ''), '[]');
    if ($host === '') {
        return '';
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return wm_music_public_ip($host) ? $url : '';
    }
    $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?? '';
    if ($host === '' || strpos($host, '.') === false) {
        return '';
    }
    if (in_array(strtolower($host), ['localhost', 'localhost.localdomain'], true)) {
        return '';
    }
    $ip = gethostbyname($host);
    // 解析失败时 gethostbyname 原样返回主机名，此时无法判定归属，一律拒绝
    if ($ip === $host || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return '';
    }
    return wm_music_public_ip($ip) ? $url : '';
}

/** 公网 IP 判定：私有段/回环/保留段返回 false */
function wm_music_public_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * 存入数据库前的 URL 清洗（不发起请求，因此不做 DNS 解析）
 * 只认 http(s)，其余（javascript:、data:、相对路径等）一律清空。
 */
function wm_music_store_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 1000) {
        return '';
    }
    $p = parse_url($url);
    if (!is_array($p)) {
        return '';
    }
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    $host = trim((string)($p['host'] ?? ''), '[]');
    if ($host === '' || preg_match('/[^A-Za-z0-9.\-:\[\]]/', $host)) {
        return '';
    }
    return $url;
}

/** 把相对地址补成绝对地址（用于跟随重定向） */
function wm_music_abs_url(string $base, string $loc): string
{
    $loc = trim($loc);
    if ($loc === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $loc)) {
        return $loc;
    }
    if (strpos($loc, '//') === 0) {
        return (preg_match('#^https://#i', $base) ? 'https:' : 'http:') . $loc;
    }
    $p = parse_url($base);
    if (!is_array($p) || empty($p['host'])) {
        return '';
    }
    $root = (preg_match('#^https#i', (string)($p['scheme'] ?? 'http')) ? 'https' : 'http')
        . '://' . $p['host'] . (isset($p['port']) ? ':' . (int)$p['port'] : '');
    if (strpos($loc, '/') === 0) {
        return $root . $loc;
    }
    $path = (string)($p['path'] ?? '/');
    $dir = substr($path, 0, (int)strrpos($path, '/') + 1);
    return $root . ($dir === '' ? '/' : $dir) . $loc;
}

/**
 * 发起一次出网 GET 请求
 * @return array{ok:bool,msg:string,data:string}
 */
function wm_music_http(string $url, string $referer = '', int $maxBytes = 1048576, string $accept = '*/*'): array
{
    // 单跳的 DNS 解析不受 curl 超时约束，这里放开执行上限，改由下面的总预算兜底
    @set_time_limit(0);
    $hops = 0;
    while ($hops <= WM_MUSIC_MAX_HOPS) {
        $left = wm_music_budget_left();
        if ($left <= 0.5) {
            return ['ok' => false, 'msg' => '获取音乐信息超时，请重试或手工填写', 'data' => ''];
        }
        $safe = wm_music_safe_url($url);
        if ($safe === '') {
            return ['ok' => false, 'msg' => '链接不受支持或指向内网地址，已阻止访问', 'data' => ''];
        }
        // 单跳超时不得超过剩余预算，留出余量给解析与后续跳
        $timeout = (int)max(2, min(8, ceil($left)));
        $connect = (int)max(1, min(4, ceil($left)));
        $headers = ['Accept: ' . $accept, 'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8'];
        if ($referer !== '') {
            $headers[] = 'Referer: ' . $referer;
        }

        if (function_exists('curl_init')) {
            $loc = '';
            $ch = curl_init($safe);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                // 重定向手动跟随：每跳重新做 URL 安全校验
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connect,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => WM_MUSIC_UA,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$loc): int {
                    if (stripos($line, 'Location:') === 0) {
                        $loc = trim(substr($line, 9));
                    }
                    return strlen($line);
                },
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                return ['ok' => false, 'msg' => $err !== '' ? $err : '网络请求失败', 'data' => ''];
            }
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'max_redirects' => 0,
                    'user_agent' => WM_MUSIC_UA,
                    'header' => implode("\r\n", $headers),
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($safe, false, $ctx);
            $code = 0;
            $loc = '';
            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $code = (int)$m[1];
                } elseif (stripos($line, 'Location:') === 0) {
                    $loc = trim(substr($line, 9));
                }
            }
            if ($body === false) {
                return ['ok' => false, 'msg' => '网络请求失败（服务器可能禁用了 allow_url_fopen）', 'data' => ''];
            }
        }

        if ($code >= 300 && $code < 400 && $loc !== '') {
            $next = wm_music_abs_url($safe, $loc);
            if ($next === '') {
                return ['ok' => false, 'msg' => '重定向地址无效', 'data' => ''];
            }
            $url = $next;
            $hops++;
            continue;
        }
        if ($code >= 400) {
            return ['ok' => false, 'msg' => '接口返回 HTTP ' . $code, 'data' => ''];
        }
        if (strlen($body) > $maxBytes) {
            $body = substr($body, 0, $maxBytes);
        }
        return ['ok' => true, 'msg' => 'ok', 'data' => (string)$body];
    }
    return ['ok' => false, 'msg' => '重定向次数过多', 'data' => ''];
}

/** 归一化并裁剪各字段长度，返回可入库 / 可回显的统一结构 */
function wm_music_pack(array $d): array
{
    $clean = static function ($v, int $max): string {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$v) ?? '');
        return mb_substr($v, 0, $max);
    };
    $out = [
        'platform'  => $clean($d['platform'] ?? 'link', 20),
        'song_id'   => $clean($d['song_id'] ?? '', 100),
        'song_name' => $clean($d['song_name'] ?? '', 200),
        'artist'    => $clean($d['artist'] ?? '', 200),
        'album'     => $clean($d['album'] ?? '', 200),
        'cover'     => mb_substr(wm_music_store_url((string)($d['cover'] ?? '')), 0, 500),
        'url'       => mb_substr(wm_music_store_url((string)($d['url'] ?? '')), 0, 500),
        'audio'     => mb_substr(wm_music_store_url((string)($d['audio'] ?? '')), 0, 500),
    ];
    if (!isset(wm_music_platforms()[$out['platform']])) {
        $out['platform'] = 'link';
    }
    if ($out['song_id'] === '') {
        // 手工填写时没有原始 ID，用「平台+歌名+歌手」派生一个稳定的伪 ID 去重
        $out['song_id'] = substr(md5($out['platform'] . '|' . $out['song_name'] . '|' . $out['artist']), 0, 32);
    }
    return $out;
}

/** 从候选键里取第一个非空值（各平台接口字段命名不统一） */
function wm_music_pick(array $d, array $keys): string
{
    foreach ($keys as $k) {
        if (isset($d[$k]) && is_scalar($d[$k]) && trim((string)$d[$k]) !== '') {
            return (string)$d[$k];
        }
    }
    return '';
}

/** 猜测平台：链接看域名，纯 ID 按形态区分网易云（纯数字）与 QQ（字母数字） */
function wm_music_detect_platform(string $input): string
{
    $low = strtolower($input);
    if (strpos($low, '163.com') !== false) { return 'netease'; }
    if (strpos($low, 'qq.com') !== false) { return 'qq'; }
    if (strpos($low, 'kugou.com') !== false || strpos($low, 'kgimg.com') !== false) { return 'kugou'; }
    if (strpos($low, 'kuwo.cn') !== false) { return 'kuwo'; }
    if (strpos($low, 'apple.com') !== false) { return 'apple'; }
    if (strpos($low, 'spotify.com') !== false) { return 'spotify'; }
    if (preg_match('#^https?://#i', $input)) { return 'link'; }
    if (preg_match('/^\d+$/', $input)) { return 'netease'; }
    if (preg_match('/^[A-Za-z0-9]{10,}$/', $input)) { return 'qq'; }
    return 'netease';
}

/** 从输入内容（链接或裸 ID）中提取该平台的歌曲标识 */
function wm_music_extract_id(string $platform, string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    switch ($platform) {
        case 'netease':
            if (preg_match('#[?&]id=(\d+)#', $input, $m)) { return $m[1]; }
            return preg_replace('/\D/', '', $input) ?? '';
        case 'qq':
            if (preg_match('#songDetail/([A-Za-z0-9]+)#i', $input, $m)) { return $m[1]; }
            if (preg_match('#[?&](?:songmid|song_id)=([A-Za-z0-9]+)#i', $input, $m)) { return $m[1]; }
            return preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '';
        case 'kugou':
            if (preg_match('#[?&]hash=([A-Za-z0-9]+)#i', $input, $m)) { return $m[1]; }
            if (preg_match('#/song/([A-Za-z0-9]+)#i', $input, $m)) { return $m[1]; }
            return preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '';
        case 'kuwo':
            if (preg_match('#[?&](?:rid|musicId|Music_Id)=(\d+)#i', $input, $m)) { return $m[1]; }
            if (preg_match('#/yinyue/(\d+)#i', $input, $m)) { return $m[1]; }
            return preg_replace('/\D/', '', $input) ?? '';
        case 'apple':
            if (preg_match('#[?&]i=(\d+)#', $input, $m)) { return $m[1]; }
            if (preg_match('#/(?:song|album|playlist)/[^/]*?(\d{5,})#i', $input, $m)) { return $m[1]; }
            return preg_replace('/\D/', '', $input) ?? '';
        case 'spotify':
            if (preg_match('#spotify\.com/(?:intl-[a-z]{2}/)?track/([A-Za-z0-9]+)#i', $input, $m)) { return $m[1]; }
            return preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '';
        case 'link':
        default:
            // 通用链接没有平台 ID，用 URL 指纹充当，天然幂等去重
            return substr(md5($input), 0, 32);
    }
}

/**
 * 按平台抓取歌曲信息
 * @return array{ok:bool,msg:string,data?:array}
 */
function wm_music_fetch(string $platform, string $input): array
{
    // 整条抓取链路的出网总预算，超时返回可读错误而不是把 PHP 拖到执行上限
    wm_music_budget(14.0);
    $input = trim($input);
    if ($input === '') {
        return ['ok' => false, 'msg' => '请输入音乐 ID 或链接'];
    }
    try {
        $platforms = wm_music_platforms();
        if (!isset($platforms[$platform])) {
            $platform = wm_music_detect_platform($input);
        }
        $songId = wm_music_extract_id($platform, $input);
        if ($songId === '') {
            return ['ok' => false, 'msg' => '无法识别歌曲 ID，请检查链接是否完整'];
        }

        switch ($platform) {
            case 'netease': $r = wm_music_netease($songId); break;
            case 'qq':      $r = wm_music_qq($songId); break;
            case 'kugou':   $r = wm_music_kugou($songId); break;
            case 'kuwo':    $r = wm_music_kuwo($songId); break;
            case 'apple':   $r = wm_music_apple($songId); break;
            case 'spotify': $r = wm_music_spotify($songId); break;
            default:        $r = wm_music_by_page($input, $songId); break;
        }
        if (!$r['ok']) {
            return $r;
        }
        $data = wm_music_pack($r['data']);
        if ($data['song_name'] === '') {
            return ['ok' => false, 'msg' => '未能获取到歌曲名，请手工填写'];
        }
        return ['ok' => true, 'msg' => '获取成功', 'data' => $data];
    } catch (Throwable $e) {
        // 任何意外都要变成可读的 JSON 错误，否则前端只能显示「服务器响应异常」
        error_log('music fetch fail: ' . $e->getMessage());
        return ['ok' => false, 'msg' => '获取音乐信息出错，请手工填写'];
    }
}

/** 网易云音乐：公开歌曲详情接口，并附带可直链播放的外链地址 */
function wm_music_netease(string $id): array
{
    $id = preg_replace('/\D/', '', $id) ?? '';
    if ($id === '') {
        return ['ok' => false, 'msg' => '网易云音乐 ID 只能是数字'];
    }
    $url = 'https://music.163.com/api/song/detail/?id=' . $id . '&ids=%5B' . $id . '%5D';
    $r = wm_music_http($url, 'https://music.163.com/');
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => '无法访问网易云接口：' . $r['msg']];
    }
    $j = json_decode($r['data'], true);
    $song = is_array($j) ? ($j['songs'][0] ?? null) : null;
    if (!is_array($song)) {
        return ['ok' => false, 'msg' => '未找到该歌曲（ID 可能不正确）'];
    }
    $artists = [];
    foreach ((array)($song['artists'] ?? []) as $a) {
        if (is_array($a) && (string)($a['name'] ?? '') !== '') {
            $artists[] = (string)$a['name'];
        }
    }
    return ['ok' => true, 'data' => wm_music_pack([
        'platform'  => 'netease',
        'song_id'   => $id,
        'song_name' => (string)($song['name'] ?? ''),
        'artist'    => implode(' / ', $artists),
        'album'     => (string)($song['album']['name'] ?? ''),
        'cover'     => (string)($song['album']['picUrl'] ?? ''),
        'url'       => 'https://music.163.com/song?id=' . $id,
        'audio'     => 'https://music.163.com/song/media/outer/url?id=' . $id . '.mp3',
    ])];
}

/** QQ音乐：songmid 对应曲库，封面走固定 CDN 规则，无需接口返回 */
function wm_music_qq(string $mid): array
{
    $mid = preg_replace('/[^A-Za-z0-9]/', '', $mid) ?? '';
    if (strlen($mid) < 8) {
        return ['ok' => false, 'msg' => 'QQ 音乐 songmid 长度不正确'];
    }
    $url = 'https://c.y.qq.com/base/fcgi-bin/fcg_music_express_qqmusic.fcg?callback=wmcb&format=json&g_tk=5381'
        . '&loginUin=0&hostUin=0&inCharset=utf8&outCharset=utf-8&notice=0&platform=yqq.json&needNewCode=0&songmid='
        . rawurlencode($mid);
    $r = wm_music_http($url, 'https://y.qq.com/');
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => '无法访问 QQ 音乐接口：' . $r['msg']];
    }
    // 接口返回 JSONP，剥掉外层回调函数再解析
    $txt = preg_replace('#^\s*[A-Za-z0-9_$.]+\s*\((.*)\)\s*;?\s*$#s', '$1', $r['data']);
    $j = json_decode((string)$txt, true);
    $d = is_array($j) ? ($j['data'] ?? null) : null;
    if (!is_array($d) || trim((string)($d['songname'] ?? '')) === '') {
        return ['ok' => false, 'msg' => '未找到该歌曲（QQ 音乐接口可能已变更），可切换平台或手工填写'];
    }
    $artists = [];
    foreach ((array)($d['singer'] ?? []) as $s) {
        if (is_array($s) && (string)($s['name'] ?? '') !== '') {
            $artists[] = (string)$s['name'];
        }
    }
    return ['ok' => true, 'data' => wm_music_pack([
        'platform'  => 'qq',
        'song_id'   => $mid,
        'song_name' => (string)$d['songname'],
        'artist'    => implode(' / ', $artists),
        'album'     => (string)($d['albumname'] ?? ''),
        'cover'     => 'https://y.gtimg.cn/music/photo_new/T002R500x500M000' . $mid . '.jpg',
        'url'       => 'https://y.qq.com/n/ryqq/songDetail/' . $mid,
    ])];
}

/** 酷狗音乐：按 hash 取播放信息（含封面） */
function wm_music_kugou(string $hash): array
{
    $hash = preg_replace('/[^A-Za-z0-9]/', '', $hash) ?? '';
    if (strlen($hash) < 16) {
        return ['ok' => false, 'msg' => '酷狗歌曲 hash 长度不正确'];
    }
    $url = 'https://www.kugou.com/yy/index.php?r=play/getdata&hash=' . rawurlencode($hash) . '&album_id=&_=' . time();
    $r = wm_music_http($url, 'https://www.kugou.com/');
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => '无法访问酷狗接口：' . $r['msg']];
    }
    $j = json_decode($r['data'], true);
    $d = is_array($j) ? ($j['data'] ?? null) : null;
    if (!is_array($d) || trim(wm_music_pick($d, ['song_name', 'songname'])) === '') {
        return ['ok' => false, 'msg' => '未找到该歌曲，可手工填写'];
    }
    return ['ok' => true, 'data' => wm_music_pack([
        'platform'  => 'kugou',
        'song_id'   => $hash,
        'song_name' => wm_music_pick($d, ['song_name', 'songname']),
        'artist'    => wm_music_pick($d, ['singer_name', 'singername', 'author_name']),
        'album'     => wm_music_pick($d, ['album_name', 'albumname']),
        'cover'     => wm_music_pick($d, ['img', 'cover', 'album_img']),
        'url'       => 'https://www.kugou.com/song/#hash=' . $hash,
    ])];
}

/** 酷我音乐：按 rid 取单曲信息（接口字段命名不稳定，逐个兜底） */
function wm_music_kuwo(string $rid): array
{
    $rid = preg_replace('/\D/', '', $rid) ?? '';
    if ($rid === '') {
        return ['ok' => false, 'msg' => '酷我歌曲 ID 只能是数字'];
    }
    $url = 'https://www.kuwo.cn/api/www/search/playMusicInfo?needNewCode=1&mid=' . $rid;
    $r = wm_music_http($url, 'https://www.kuwo.cn/');
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => '无法访问酷我接口：' . $r['msg']];
    }
    $j = json_decode($r['data'], true);
    $d = is_array($j) ? (($j['data'] ?? null) ?: $j) : null;
    if (!is_array($d)) {
        return ['ok' => false, 'msg' => '未找到该歌曲，可手工填写'];
    }
    $name = wm_music_pick($d, ['songName', 'songname', 'name', 'musicName']);
    if ($name === '') {
        return ['ok' => false, 'msg' => '未找到该歌曲，可手工填写'];
    }
    return ['ok' => true, 'data' => wm_music_pack([
        'platform'  => 'kuwo',
        'song_id'   => $rid,
        'song_name' => $name,
        'artist'    => wm_music_pick($d, ['artistName', 'artistname', 'singerName', 'singername']),
        'album'     => wm_music_pick($d, ['albumName', 'albumname']),
        'cover'     => wm_music_pick($d, ['bigPic', 'img', 'pic', 'albumImg']),
        'url'       => 'https://www.kuwo.cn/yinyue/' . $rid,
    ])];
}

/** Apple Music：走 iTunes 公开查询接口，附带官方试听地址 */
function wm_music_apple(string $id): array
{
    $id = preg_replace('/\D/', '', $id) ?? '';
    if ($id === '') {
        return ['ok' => false, 'msg' => 'Apple Music 歌曲 ID 只能是数字'];
    }
    $url = 'https://itunes.apple.com/lookup?id=' . $id . '&entity=song&limit=1';
    $r = wm_music_http($url, 'https://music.apple.com/');
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => '无法访问 Apple Music 接口：' . $r['msg']];
    }
    $j = json_decode($r['data'], true);
    $it = is_array($j) ? ($j['results'][0] ?? null) : null;
    if (!is_array($it) || trim((string)($it['trackName'] ?? '')) === '') {
        return ['ok' => false, 'msg' => '未找到该歌曲，可手工填写'];
    }
    // 专辑 ID 也会命中这里，kind 不是 song 时直接判定为取错类型
    if (isset($it['kind']) && (string)$it['kind'] !== 'song') {
        return ['ok' => false, 'msg' => '该 ID 不是单曲，请改用单曲链接（含 ?i= 参数）'];
    }
    $cover = (string)($it['artworkUrl100'] ?? '');
    $cover = $cover !== '' ? preg_replace('/100x100bb/', '600x600bb', $cover) : '';
    return ['ok' => true, 'data' => wm_music_pack([
        'platform'  => 'apple',
        'song_id'   => $id,
        'song_name' => (string)$it['trackName'],
        'artist'    => (string)($it['artistName'] ?? ''),
        'album'     => (string)($it['collectionName'] ?? ''),
        'cover'     => $cover,
        'url'       => (string)($it['trackViewUrl'] ?? ('https://music.apple.com/us/search?term=' . rawurlencode((string)($it['trackName'] ?? '')))),
        'audio'     => (string)($it['previewUrl'] ?? ''),
    ])];
}

/** Spotify：走官方 oEmbed（公开、免鉴权），标题里带歌手信息 */
function wm_music_spotify(string $id): array
{
    $id = preg_replace('/[^A-Za-z0-9]/', '', $id) ?? '';
    if ($id === '') {
        return ['ok' => false, 'msg' => 'Spotify 链接中未识别到 track ID'];
    }
    $page = 'https://open.spotify.com/track/' . $id;
    $url = 'https://open.spotify.com/oembed?url=' . rawurlencode($page);
    $r = wm_music_http($url, 'https://open.spotify.com/');
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => '无法访问 Spotify 接口：' . $r['msg']];
    }
    $j = json_decode($r['data'], true);
    $title = is_array($j) ? trim((string)($j['title'] ?? '')) : '';
    if ($title === '') {
        return ['ok' => false, 'msg' => '未找到该歌曲，可手工填写'];
    }
    $name = $title;
    $artist = '';
    $album = '';
    // oEmbed 标题常见两种形态："歌名" 或 "歌名 · 歌手 · 专辑"
    $parts = array_values(array_filter(array_map('trim', explode('·', $title)), static fn($s) => $s !== ''));
    if (count($parts) >= 2) {
        $name = $parts[0];
        $artist = $parts[1];
        $album = $parts[2] ?? '';
    } elseif (strpos($name, ' - ') !== false) {
        [$name, $artist] = array_pad(explode(' - ', $name, 2), 2, '');
        $name = trim($name);
        $artist = trim($artist);
    }
    return ['ok' => true, 'data' => wm_music_pack([
        'platform'  => 'spotify',
        'song_id'   => $id,
        'song_name' => $name,
        'artist'    => $artist,
        'album'     => $album,
        'cover'     => is_array($j) ? (string)($j['thumbnail_url'] ?? '') : '',
        'url'       => $page,
    ])];
}

/**
 * 通用兜底：抓取音乐页面并读取 og / music 系列 meta
 * 只认 https/http 且目标必须解析到公网 IP，因此可安全用于「其他音乐链接」。
 */
function wm_music_by_page(string $pageUrl, string $songId): array
{
    $r = wm_music_http($pageUrl, '', 524288, 'text/html,application/xhtml+xml');
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => '无法打开该页面：' . $r['msg']];
    }
    $html = $r['data'];
    if (trim($html) === '') {
        return ['ok' => false, 'msg' => '页面内容为空，可手工填写'];
    }
    $meta = [];
    if (preg_match_all('#<meta\b[^>]*>#i', $html, $ms)) {
        foreach ($ms[0] as $tag) {
            $k = preg_match('#\b(?:property|name)\s*=\s*"([^"]*)"#i', $tag, $km)
                || preg_match("#\\b(?:property|name)\\s*=\\s*'([^']*)'#i", $tag, $km)
                ? strtolower(trim($km[1])) : '';
            if ($k === '') {
                continue;
            }
            $v = preg_match('#\bcontent\s*=\s*"([^"]*)"#i', $tag, $vm)
                || preg_match("#\\bcontent\\s*=\\s*'([^']*)'#i", $tag, $vm)
                ? trim($vm[1]) : '';
            if ($v !== '' && !isset($meta[$k])) {
                $meta[$k] = $v;
            }
        }
    }
    $pick = static function (array $keys) use ($meta): string {
        foreach ($keys as $k) {
            if (!empty($meta[$k])) {
                return $meta[$k];
            }
        }
        return '';
    };

    $name = $pick(['og:title', 'twitter:title', 'title']);
    if ($name === '' && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $tm)) {
        $name = trim(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if ($name === '') {
        return ['ok' => false, 'msg' => '未能从该页面读取到歌曲名，可手工填写'];
    }
    $artist = $pick(['music:musician', 'og:music:musician', 'twitter:music:musician', 'artist', 'author', 'og:music:artist']);
    $album = $pick(['music:album', 'og:music:album', 'album:title']);
    $cover = $pick(['og:image', 'og:image:url', 'twitter:image', 'image']);
    // 标题常是「歌名 - 歌手」，且页面没给 artist meta 时据此拆分
    if ($artist === '' && preg_match('#^(.{1,80}?)\s+[-–—|]\s+(.{1,60})$#u', $name, $mm)) {
        $name = trim($mm[1]);
        $artist = trim($mm[2]);
    }
    return ['ok' => true, 'data' => wm_music_pack([
        'platform'  => 'link',
        'song_id'   => $songId,
        'song_name' => $name,
        'artist'    => $artist,
        'album'     => $album,
        'cover'     => $cover,
        'url'       => wm_music_store_url($pageUrl),
    ])];
}

/** 单条读取 */
function wm_music_get(int $id): ?array
{
    if ($id <= 0 || !wm_music_ready()) {
        return null;
    }
    $row = wm_one('SELECT * FROM ' . wm_t('music') . ' WHERE id = ? LIMIT 1', [$id]);
    return $row === null ? null : wm_music_pack($row);
}

/** 批量读取，返回 [music_id => 规范化数据] */
function wm_music_by_posts(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !wm_music_ready()) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    foreach (wm_all('SELECT * FROM ' . wm_t('music') . ' WHERE id IN (' . $in . ')', $ids) as $r) {
        $out[(int)$r['id']] = wm_music_pack($r);
    }
    return $out;
}

/**
 * 校验并入库（平台 + 歌曲 ID 唯一，重复分享自动复用同一条记录）
 * @return int 音乐 ID；歌名为空时返回 0
 */
function wm_music_save(array $data, int $userId = 0): int
{
    if (!wm_music_ready()) {
        return 0;
    }
    $m = wm_music_pack($data);
    if ($m['song_name'] === '') {
        return 0;
    }
    $exist = wm_one('SELECT id FROM ' . wm_t('music') . ' WHERE platform = ? AND song_id = ? LIMIT 1',
        [$m['platform'], $m['song_id']]);
    $fields = [$m['song_name'], $m['artist'], $m['album'], $m['cover'], $m['url'], $m['audio']];
    if ($exist !== null) {
        wm_exec('UPDATE ' . wm_t('music') . ' SET song_name = ?, artist = ?, album = ?, cover = ?, url = ?, audio = ?
                 WHERE id = ?', array_merge($fields, [(int)$exist['id']]));
        return (int)$exist['id'];
    }
    try {
        wm_exec('INSERT INTO ' . wm_t('music') . '
                 (platform, song_id, song_name, artist, album, cover, url, audio, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            array_merge([$m['platform'], $m['song_id']], $fields, [max(0, $userId)]));
        return wm_insert_id();
    } catch (Throwable $e) {
        // 并发下唯一键冲突：回查已存在的那条，不报错打断发布
        $row = wm_one('SELECT id FROM ' . wm_t('music') . ' WHERE platform = ? AND song_id = ? LIMIT 1',
            [$m['platform'], $m['song_id']]);
        return $row === null ? 0 : (int)$row['id'];
    }
}
