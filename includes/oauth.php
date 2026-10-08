<?php
/**
 * 第三方「聚合登录」（QQ / 微信）核心逻辑
 *
 * 接入的是「彩虹聚合登录」这类服务商的标准四步流程
 * （协议以 https://u.daib.cn/doc.php 为准，https://u.770b.cn/ 等同类站点一致）：
 *
 *   Step 1  本站后端请求
 *           {接口}?act=login&appid=..&appkey=..&type=..&redirect_uri=..
 *           返回 {"code":0,"msg":"succ","type":"qq","url":"第三方授权地址"}
 *   Step 2  本站把浏览器跳转到上一步返回的 url
 *           ⚠ 跳的是这个 url，而不是接口地址本身
 *   Step 3  用户在第三方完成授权，第三方带 ?type=qq&code=xxx 回到 redirect_uri
 *   Step 4  本站后端请求
 *           {接口}?act=callback&appid=..&appkey=..&type=..&code=..
 *           返回 {"code":0,"social_uid":"..","access_token":"..",
 *                 "nickname":"..","faceimg":"..","gender":"..","ip":".."}
 *
 * 两个必须做的兼容（否则按常规 OAuth 直觉去写必然登录失败）：
 *
 *   A. 接口地址可以只填站点根地址。
 *      管理员在后台填 https://u.daib.cn/ 即可，本类会自动补成 .../connect.php；
 *      也允许直接填完整接口地址（含路径），此时原样使用。
 *
 *   B. 该协议没有 state 参数，回调也不会回传 state。
 *      所以本站不依赖它做 CSRF 校验，而是把一次性随机串挂在自己的
 *      redirect_uri 上（?wm_state=xxx），回调时比对 session；
 *      若服务商额外支持回传 state，则两种都接受。
 *
 * 另外字段名也不是通用的 openid/avatar，而是 social_uid / faceimg，
 * 下面解析时对两套命名都做兼容。
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

require_once WM_INC . '/music.php';

/** 服务商接口的标准文件名：只填站点根地址时自动补上 */
if (!defined('WM_OAUTH_CONNECT_FILE')) {
    define('WM_OAUTH_CONNECT_FILE', 'connect.php');
}

/** 支持的聚合登录方式。type 为服务商接口的取值
 *
 * icon 为开发者内置的静态 SVG（非用户输入），前端直接内联输出。
 * 早期版本这里用的是「蓝色圆 + 字母 Q」和「圆 + 微」文字，
 * 用户辨识度低，现改为 QQ 企鹅与微信气泡标志。
 */
function wm_oauth_methods(): array
{
    return [
        'qq' => [
            'name' => 'QQ', 'color' => '#12b7f5', 'type' => 'qq',
            'icon' => '<svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
                . '<path fill="#fff" d="M16 2.6c-5 0-9 3.3-9 7.5 0 .7.1 1.4.3 2-1.5.6-2.7 1.7-3.5 3.1-.7 1.3-1.1 2.8-1.1 4.3 0 1.6.5 3.1 1.4 4.4-.5 1-.8 2.1-.8 3.2 0 4.6 5.5 8.3 12.3 8.3h1.4c6.8 0 12.3-3.7 12.3-8.3 0-1.1-.3-2.2-.8-3.2.9-1.3 1.4-2.8 1.4-4.4 0-1.5-.4-3-1.1-4.3-.8-1.4-2-2.5-3.5-3.1.2-.6.3-1.3.3-2 0-4.2-4-7.5-9-7.5Z"/>'
                . '<path fill="#f5a623" d="M16 13.9c2.1 0 3.8 1 3.8 2.2 0 .8-.7 1.4-1.7 1.4-.6 0-1.1-.2-1.4-.6-.3.4-.8.6-1.4.6-1 0-1.7-.6-1.7-1.4 0-1.2 1.7-2.2 3.8-2.2Z"/>'
                . '<ellipse cx="12.6" cy="11.6" rx="1.5" ry="1.7" fill="#12b7f5"/>'
                . '<ellipse cx="19.4" cy="11.6" rx="1.5" ry="1.7" fill="#12b7f5"/>'
                . '<path fill="#ffd43b" d="M8.2 29.4c-1.6.3-2.9 1-2.9 1.9 0 .9 1.3 1.6 2.9 1.6.6 0 1.2-.1 1.7-.2-.6-.9-.9-2-.9-3.3h-.8Z"/>'
                . '<path fill="#ffd43b" d="M23.8 29.4h-.8c0 1.3-.3 2.4-.9 3.3.5.1 1.1.2 1.7.2 1.6 0 2.9-.7 2.9-1.6 0-.9-1.3-1.6-2.9-1.9Z"/>'
                . '</svg>',
        ],
        'wechat' => [
            'name' => '微信', 'color' => '#07c160', 'type' => 'wx',
            'icon' => '<svg viewBox="0 0 32 32" aria-hidden="true" focusable="false">'
                . '<path fill="#fff" d="M13.1 4C6.9 4 2 7.9 2 12.8c0 2.8 1.6 5.3 4.1 6.9l-1 3.6 4-2.1c1.2.3 2.5.5 3.9.5h.6a5.9 5.9 0 0 1-.2-1.5c0-4.1 4.2-7.4 9.3-7.4h.6C22.8 8.4 18.5 4 13.1 4Z"/>'
                . '<path fill="#fff" d="M30 19.6c0-3.7-4-6.7-8.9-6.7s-8.9 3-8.9 6.7 4 6.7 8.9 6.7c1.1 0 2.2-.2 3.2-.5l3.3 1.8-.8-3c1.4-1.3 2.2-3 2.2-5Z"/>'
                . '<ellipse cx="9.4" cy="11.4" rx="1.5" ry="1.8" fill="#12b7f5"/>'
                . '<ellipse cx="16.8" cy="11.4" rx="1.5" ry="1.8" fill="#12b7f5"/>'
                . '<ellipse cx="17.9" cy="18.4" rx="1.3" ry="1.6" fill="#07c160"/>'
                . '<ellipse cx="24.1" cy="18.4" rx="1.3" ry="1.6" fill="#07c160"/>'
                . '</svg>',
        ],
    ];
}

/** 把服务商返回的 type 归一化成本站的 provider 键 */
function wm_oauth_provider_key(string $raw): string
{
    $alias = [
        'qq' => 'qq',
        'wx' => 'wechat',
        'wechat' => 'wechat',
        'weixin' => 'wechat',
    ];
    return $alias[strtolower(trim($raw))] ?? '';
}

/** 读取聚合登录配置
 *
 *  ⚠️ appkey 必须 wm_secret_decode：后台保存时做了 AES 加密（存的是 enc:xxx），
 *     而 wm_setting() 只读原始值不做解密。直接把密文当 appkey 发出去，
 *     服务商会返回 code=-1 appkey不正确。
 */
function wm_oauth_cfg(): array
{
    return [
        'on'     => (string)wm_setting('oauth_on', '0') === '1',
        'api'    => trim((string)wm_setting('oauth_api', '')),
        'appid'  => trim((string)wm_setting('oauth_appid', '')),
        'appkey' => trim(wm_secret_decode((string)wm_setting('oauth_appkey', ''))),
        'auto'   => (string)wm_setting('oauth_auto_register', '1') === '1',
        'methods' => array_values(array_intersect(
            array_keys(wm_oauth_methods()),
            array_filter(explode(',', (string)wm_setting('oauth_methods', 'qq,wechat')))
        )),
    ];
}

/** 是否可用（开关打开且接口与凭据齐备，且数据表已就绪） */
function wm_oauth_enabled(): bool
{
    $c = wm_oauth_cfg();
    return $c['on'] && $c['api'] !== '' && $c['appid'] !== '' && $c['appkey'] !== ''
        && $c['methods'] !== [] && wm_oauth_ready();
}

/** 绑定表是否已就绪（未执行 upgrade.sql 时降级，不让整站 500） */
function wm_oauth_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        wm_db()->query('SELECT 1 FROM ' . wm_t('user_oauth') . ' LIMIT 1');
        $ready = true;
    } catch (Throwable $e) {
        error_log('oauth schema check fail: ' . $e->getMessage());
        $ready = false;
    }
    return $ready;
}

/**
 * 归一化接口地址
 *
 * 「只填站点根地址」是这类服务商最常见的用法，必须支持：
 *   https://u.daib.cn/          → https://u.daib.cn/connect.php
 *   u.daib.cn                   → https://u.daib.cn/connect.php（补协议）
 *   https://u.daib.cn/connect.php → 原样保留
 *   https://x.cn/api/login.php  → 原样保留（其他服务商的自定义路径）
 *   https://x.cn/?a=1           → 保留原 query，再追加接口文件与参数
 *
 * @param string $raw 为空或格式非法时返回 ''
 */
function wm_oauth_normalize_endpoint(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 500) {
        return '';
    }
    if (!preg_match('#^https?://#i', $raw)) {
        // 允许省略协议，但必须像个主机名，防止把任意字符串拼进 URL
        if (!preg_match('#^[A-Za-z0-9.\-]+(?::\d{1,5})?(?:/|$)#', $raw)) {
            return '';
        }
        $raw = 'https://' . $raw;
    }
    $p = parse_url($raw);
    if (!is_array($p) || empty($p['host'])) {
        return '';
    }
    $scheme = strtolower((string)($p['scheme'] ?? 'https'));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    $path = trim((string)($p['path'] ?? ''), '/');
    if ($path === '') {
        // 只填了站点根地址 → 补上标准接口文件
        $path = WM_OAUTH_CONNECT_FILE;
    }
    $ep = $scheme . '://' . $p['host'];
    if (!empty($p['port'])) {
        $ep .= ':' . (int)$p['port'];
    }
    $ep .= '/' . $path;
    if (!empty($p['query'])) {
        $ep .= '?' . $p['query'];
    }
    return $ep;
}

/** 当前配置的接口地址（已归一化） */
function wm_oauth_endpoint(): string
{
    return wm_oauth_normalize_endpoint((string)wm_setting('oauth_api', ''));
}

/** 在接口地址上追加参数（保留原有可能存在的 query，用 & 衔接） */
function wm_oauth_url(array $params): string
{
    $ep = wm_oauth_endpoint();
    if ($ep === '') {
        return '';
    }
    $sep = strpos($ep, '?') === false ? '?' : '&';
    return $ep . $sep . http_build_query($params);
}

/** 本站回调地址（第三方授权完成后回到这里） */
function wm_oauth_callback_url(): string
{
    return wm_site_root_url() . 'user/oauth_callback.php';
}

/**
 * 生成并记住一次性随机串
 *
 * 该协议没有 state，所以这个串会被拼进 redirect_uri（?wm_state=xxx），
 * 回调时取出比对，用它替代 state 完成 CSRF 防护。
 */
function wm_oauth_state(): string
{
    $s = wm_random(24);
    $_SESSION['_oauth_state'] = $s;
    $_SESSION['_oauth_time'] = time();
    return $s;
}

/** 取出并清除 session 中的随机串（取出即失效，防重放） */
function wm_oauth_take_state(): string
{
    $s = (string)($_SESSION['_oauth_state'] ?? '');
    $at = (int)($_SESSION['_oauth_time'] ?? 0);
    unset($_SESSION['_oauth_state'], $_SESSION['_oauth_time']);
    return ($s !== '' && $at > 0 && time() - $at <= 1800) ? $s : '';
}

/** 记住本次发起的登录方式，回调时以它为准（不信任回调里的 type） */
function wm_oauth_set_method(string $method): void
{
    $_SESSION['_oauth_method'] = $method;
}

function wm_oauth_get_method(): string
{
    $m = (string)($_SESSION['_oauth_method'] ?? '');
    unset($_SESSION['_oauth_method']);
    return $m;
}

/**
 * 调用服务商接口并解析 JSON
 * @return array{ok:bool,msg:string,json:array}
 */
function wm_oauth_request(string $act, array $params): array
{
    $url = wm_oauth_url(['act' => $act] + $params);
    if ($url === '') {
        return ['ok' => false, 'msg' => '聚合登录接口地址无效，请检查后台配置', 'json' => []];
    }
    wm_music_budget(12.0);
    $r = wm_music_http($url, '', 262144, 'application/json,text/plain,*/*');
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => '接口请求失败：' . (string)$r['msg'], 'json' => []];
    }
    $body = trim((string)$r['data']);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return [
            'ok' => false,
            'msg' => '接口返回的不是 JSON（通常是接口地址填错，或该地址需要填写完整接口路径）',
            'json' => [],
        ];
    }
    // 该协议 code=0 为成功，2 表示未完成登录
    if ((int)($json['code'] ?? -1) !== 0) {
        $msg = trim((string)($json['msg'] ?? ''));
        return [
            'ok' => false,
            'msg' => '接口返回错误 code=' . (int)($json['code'] ?? -1)
                . ($msg !== '' ? '：' . $msg : '（请检查 APPID / APPKEY 是否正确）'),
            'json' => $json,
        ];
    }
    return ['ok' => true, 'msg' => 'ok', 'json' => $json];
}

/**
 * Step 1 + Step 2：拿到第三方授权地址
 * @return array{ok:bool,msg:string,url:string}
 */
function wm_oauth_authorize_url(string $method): array
{
    $c = wm_oauth_cfg();
    $methods = wm_oauth_methods();
    if (!isset($methods[$method])) {
        return ['ok' => false, 'msg' => '不支持的登录方式', 'url' => ''];
    }
    if ($c['appid'] === '' || $c['appkey'] === '') {
        return ['ok' => false, 'msg' => '请先在后台填写应用 APPID 与 APPKEY', 'url' => ''];
    }
    if (wm_oauth_endpoint() === '') {
        return ['ok' => false, 'msg' => '聚合登录接口地址无效，请检查后台配置', 'url' => ''];
    }

    wm_oauth_set_method($method);
    $state = wm_oauth_state();

    $r = wm_oauth_request('login', [
        'appid'         => $c['appid'],
        'appkey'        => $c['appkey'],
        'type'          => $methods[$method]['type'],
        'redirect_uri'  => wm_oauth_callback_url() . '?wm_state=' . rawurlencode($state),
        // 该协议未声明支持 state，携带无害；若服务商支持则会原样回传，本站一并接受
        'state'         => $state,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => (string)$r['msg'], 'url' => ''];
    }

    $target = trim((string)($r['json']['url'] ?? ''));
    if ($target === '' || !preg_match('#^https?://#i', $target)) {
        return ['ok' => false, 'msg' => '接口未返回有效的第三方授权地址（缺少 url 字段）', 'url' => ''];
    }
    return ['ok' => true, 'msg' => 'ok', 'url' => $target];
}

/**
 * Step 4：用 code 换第三方用户信息
 * @return array{ok:bool,msg:string,user:array}
 */
function wm_oauth_fetch_user(string $method, string $code): array
{
    $c = wm_oauth_cfg();
    $methods = wm_oauth_methods();
    $code = trim($code);
    if ($code === '') {
        return ['ok' => false, 'msg' => '回调缺少 code 参数', 'user' => []];
    }
    if (!isset($methods[$method])) {
        return ['ok' => false, 'msg' => '未知的登录方式：' . $method, 'user' => []];
    }
    $r = wm_oauth_request('callback', [
        'appid'  => $c['appid'],
        'appkey' => $c['appkey'],
        'type'   => $methods[$method]['type'],
        'code'   => $code,
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => (string)$r['msg'], 'user' => []];
    }
    return wm_oauth_normalize($r['json'], $method);
}

/**
 * 把服务商返回的 JSON 归一化成 {provider, openid, nickname, avatar}
 *
 * 该协议用 social_uid 表示第三方用户标识、faceimg 表示头像；
 * 同时兼容 openid/avatar 这套通用命名，以及常见的 {code,data:{...}} 外壳。
 *
 * @return array{ok:bool,msg:string,user:array}
 */
function wm_oauth_normalize(array $json, string $method = ''): array
{
    $root = $json;
    foreach (['data', 'user', 'info', 'result'] as $wrap) {
        if (isset($root[$wrap]) && is_array($root[$wrap]) && $root[$wrap] !== []) {
            $root = $root[$wrap];
            break;
        }
    }

    $openid = '';
    foreach (['social_uid', 'openid', 'open_id', 'unionid', 'union_id', 'uin', 'sub'] as $k) {
        if (isset($root[$k]) && is_scalar($root[$k])) {
            $openid = trim((string)$root[$k]);
            if ($openid !== '') {
                break;
            }
        }
    }
    if ($openid === '') {
        $openid = wm_oauth_deep($root, ['social_uid', 'openid', 'open_id', 'unionid', 'uin', 'sub']);
    }
    if ($openid === '') {
        $msg = trim((string)($root['msg'] ?? ''));
        return ['ok' => false, 'msg' => $msg !== '' ? $msg : '接口未返回第三方用户标识（social_uid）', 'user' => []];
    }

    $provider = $method !== '' ? $method : wm_oauth_provider_key((string)($root['type'] ?? ''));
    if ($provider === '') {
        $provider = 'qq';
    }

    $nickname = trim((string)($root['nickname'] ?? ''));
    if ($nickname === '') {
        $nickname = wm_oauth_deep($root, ['nickname', 'nick', 'name', 'username']);
    }
    if (mb_strlen($nickname) > 20) {
        $nickname = mb_substr($nickname, 0, 20);
    }

    $avatar = trim((string)($root['faceimg'] ?? ''));
    if ($avatar === '') {
        $avatar = wm_oauth_deep($root, ['faceimg', 'avatar', 'avatar_url', 'figureurl', 'figureurl_qq_2', 'headimgurl', 'pic']);
    }
    // 只接受 http(s) 头像地址，避免把 javascript: 之类写进 img src
    if ($avatar !== '' && !preg_match('#^https?://#i', $avatar)) {
        $avatar = '';
    }

    return ['ok' => true, 'msg' => 'ok', 'user' => [
        'provider' => $provider,
        'openid'   => mb_substr($openid, 0, 128),
        'nickname' => $nickname,
        'avatar'   => mb_substr($avatar, 0, 250),
    ]];
}

/** 在返回结构里递归找到第一个非空标量（用于兜底字段命名差异） */
function wm_oauth_deep(array $data, array $keys, int $depth = 0): string
{
    if ($depth > 3) {
        return '';
    }
    foreach ($keys as $k) {
        if (isset($data[$k]) && is_scalar($data[$k])) {
            $v = trim((string)$data[$k]);
            if ($v !== '') {
                return $v;
            }
        }
    }
    foreach ($data as $v) {
        if (is_array($v)) {
            $hit = wm_oauth_deep($v, $keys, $depth + 1);
            if ($hit !== '') {
                return $hit;
            }
        }
    }
    return '';
}

/** 按 openid 派生一个不冲突的用户名 */
function wm_oauth_make_username(string $provider, string $openid, string $nickname): string
{
    $base = strtolower($provider === 'wechat' ? 'wx' : 'qq') . '_' . substr(hash('sha256', $openid), 0, 10);
    $name = $base;
    if ($nickname !== '') {
        // 中文昵称不可用做用户名（用户名仅允许 A-Za-z0-9_），这里只做可读后缀
        $suffix = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nickname)) ?? '', 0, 4);
        if ($suffix !== '') {
            $name = $base . '_' . strtolower($suffix);
        }
    }
    $name = substr($name, 0, 30);
    $try = $name;
    for ($i = 1; $i < 50; $i++) {
        $hit = wm_value('SELECT id FROM ' . wm_t('user') . ' WHERE username = ? LIMIT 1', [$try]);
        if ($hit === null) {
            return $try;
        }
        $try = substr($name, 0, 26) . '_' . $i;
    }
    return $base . '_' . wm_random(6);
}

/**
 * 接口连通性探测
 *
 * 真实跑一遍 Step 1（act=login）：能拿到 code=0 且带 url，说明
 * 接口地址、APPID、APPKEY 三者都正确，比单纯探测域名可靠得多。
 *
 * @return array{ok:bool,msg:string}
 */
function wm_oauth_probe(): array
{
    $c = wm_oauth_cfg();
    $ep = wm_oauth_endpoint();
    if ($c['api'] === '') {
        return ['ok' => false, 'msg' => '未填写聚合登录接口地址'];
    }
    if ($ep === '') {
        return ['ok' => false, 'msg' => '接口地址格式不正确，请填写以 http:// 或 https:// 开头的地址'];
    }
    if ($c['appid'] === '' || $c['appkey'] === '') {
        return ['ok' => false, 'msg' => '请先填写应用 APPID 与 APPKEY'];
    }
    $r = wm_oauth_request('login', [
        'appid'        => $c['appid'],
        'appkey'       => $c['appkey'],
        'type'         => 'qq',
        'redirect_uri' => wm_oauth_callback_url(),
    ]);
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => (string)$r['msg']];
    }
    $target = trim((string)($r['json']['url'] ?? ''));
    if ($target === '' || !preg_match('#^https?://#i', $target)) {
        return ['ok' => false, 'msg' => '接口返回 code=0 但没有 url 字段，请确认该地址是聚合登录的 act=login 接口'];
    }
    return [
        'ok'  => true,
        'msg' => '接口可用：APPID / APPKEY 校验通过，已成功获取第三方授权地址（' . wm_cut(wm_oauth_endpoint(), 80) . '）',
    ];
}

/** 查绑定记录 */
function wm_oauth_find(string $provider, string $openid): ?array
{
    if (!wm_oauth_ready()) {
        return null;
    }
    return wm_one(
        'SELECT * FROM ' . wm_t('user_oauth') . ' WHERE provider = ? AND openid = ? LIMIT 1',
        [$provider, $openid]
    );
}

/** 某用户已绑定的快捷登录方式 */
function wm_oauth_user_binds(int $userId): array
{
    if ($userId <= 0 || !wm_oauth_ready()) {
        return [];
    }
    $rows = wm_all(
        'SELECT id, provider, openid, nickname, avatar, created_at, last_login_at
         FROM ' . wm_t('user_oauth') . ' WHERE user_id = ? ORDER BY id ASC',
        [$userId]
    );
    $defs = wm_oauth_methods();
    $out = [];
    foreach ($rows as $r) {
        $p = (string)$r['provider'];
        $out[] = [
            'id'       => (int)$r['id'],
            'provider' => $p,
            'name'     => $defs[$p]['name'] ?? $p,
            'color'    => $defs[$p]['color'] ?? '#909399',
            'openid'   => (string)$r['openid'],
            'nickname' => (string)$r['nickname'],
            'created_at' => (string)$r['created_at'],
            'last_login_at' => (string)($r['last_login_at'] ?? ''),
        ];
    }
    return $out;
}

/**
 * 把第三方身份绑定到指定用户（用于「账号设置 → 快捷绑定登录」）
 *
 * 安全要点：只允许绑到「当前已登录」的本站用户，
 * 且该第三方标识必须还没被别的账号占用。
 *
 * @return array{ok:bool,msg:string}
 */
function wm_oauth_bind(int $userId, string $provider, string $openid, string $nickname = '', string $avatar = ''): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'msg' => '请先登录本站账号'];
    }
    if (!wm_oauth_ready()) {
        return ['ok' => false, 'msg' => '数据库结构未升级，请先执行站点根目录的 upgrade.sql'];
    }
    if ($provider === '' || $openid === '') {
        return ['ok' => false, 'msg' => '第三方用户信息不完整'];
    }
    $u = wm_one('SELECT id FROM ' . wm_t('user') . ' WHERE id = ? AND status = 1 LIMIT 1', [$userId]);
    if ($u === null) {
        return ['ok' => false, 'msg' => '账号不存在或已被禁用'];
    }

    // provider 可能来自数据库里的历史脏数据，不能直接索引 wm_oauth_methods()
    $defs = wm_oauth_methods();
    $pname = $defs[$provider]['name'] ?? $provider;

    $bind = wm_oauth_find($provider, $openid);
    if ($bind !== null) {
        if ((int)$bind['user_id'] === $userId) {
            return ['ok' => false, 'msg' => '该' . $pname . '账号已经绑定过了'];
        }
        return ['ok' => false, 'msg' => '该' . $pname . '账号已绑定到本站其他用户，请先解绑'];
    }
    // 同一平台在本站只能绑一个账号，避免登录时在多个账号间跳转
    $mine = wm_one(
        'SELECT id FROM ' . wm_t('user_oauth') . ' WHERE user_id = ? AND provider = ? LIMIT 1',
        [$userId, $provider]
    );
    if ($mine !== null) {
        return ['ok' => false, 'msg' => '已绑定过' . $pname . '快捷登录，请先解绑再重新绑定'];
    }

    try {
        wm_exec(
            'INSERT INTO ' . wm_t('user_oauth') . ' (user_id, provider, openid, nickname, avatar, created_at, last_login_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$userId, $provider, $openid, mb_substr($nickname, 0, 50), mb_substr($avatar, 0, 250)]
        );
        return ['ok' => true, 'msg' => $pname . '快捷登录绑定成功'];
    } catch (Throwable $e) {
        error_log('oauth bind fail: ' . $e->getMessage());
        return ['ok' => false, 'msg' => '绑定失败，请稍后重试'];
    }
}

/** 解绑（必须是当前用户自己的绑定） */
function wm_oauth_unbind(int $userId, int $bindId): bool
{
    if ($userId <= 0 || $bindId <= 0 || !wm_oauth_ready()) {
        return false;
    }
    wm_exec(
        'DELETE FROM ' . wm_t('user_oauth') . ' WHERE id = ? AND user_id = ?',
        [$bindId, $userId]
    );
    return true;
}

/**
 * 第三方身份登录 / 注册
 * @return array{ok:bool,msg:string,user:?array,new:bool} new=true 表示本次是自动注册的新用户
 */
function wm_oauth_login(string $provider, string $openid, string $nickname, string $avatar): array
{
    if (!wm_oauth_ready()) {
        return ['ok' => false, 'msg' => '数据库结构未升级，请先执行站点根目录的 upgrade.sql', 'user' => null, 'new' => false];
    }
    if ($provider === '' || $openid === '') {
        return ['ok' => false, 'msg' => '第三方用户信息不完整', 'user' => null, 'new' => false];
    }

    $bind = wm_oauth_find($provider, $openid);
    if ($bind !== null) {
        $uid = (int)$bind['user_id'];
        $row = wm_one('SELECT * FROM ' . wm_t('user') . ' WHERE id = ? LIMIT 1', [$uid]);
        if ($row === null || (int)$row['status'] !== 1) {
            return ['ok' => false, 'msg' => '绑定的账号不存在或已被禁用，请联系管理员', 'user' => null, 'new' => false];
        }
        wm_exec(
            'UPDATE ' . wm_t('user_oauth') . ' SET last_login_at = NOW() WHERE id = ?',
            [(int)$bind['id']]
        );
        return ['ok' => true, 'msg' => 'ok', 'user' => $row, 'new' => false];
    }

    $cfg = wm_oauth_cfg();
    if (!$cfg['auto']) {
        return ['ok' => false, 'msg' => '该第三方账号尚未绑定本站用户，且未开启「自动注册」，请联系管理员处理', 'user' => null, 'new' => false];
    }

    $username = wm_oauth_make_username($provider, $openid, $nickname);
    $nick = $nickname !== '' ? $nickname : $username;
    $err = wm_nickname_error($nick);
    if ($err !== '') {
        $nick = '';
    }
    try {
        wm_exec(
            'INSERT INTO ' . wm_t('user')
            . ' (username, password, nickname, avatar, status, pass_version, last_login_at, last_login_ip, login_count, created_at)
             VALUES (?, ?, ?, ?, 1, 1, NOW(), ?, 1, NOW())',
            [$username, wm_password_hash(wm_random(16)), $nick, $avatar, wm_client_ip()]
        );
        $uid = wm_insert_id();
        wm_exec(
            'INSERT INTO ' . wm_t('user_oauth') . ' (user_id, provider, openid, nickname, avatar, created_at, last_login_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$uid, $provider, $openid, $nick, $avatar]
        );
        $row = wm_one('SELECT * FROM ' . wm_t('user') . ' WHERE id = ? LIMIT 1', [$uid]);
        return ['ok' => true, 'msg' => 'ok', 'user' => $row, 'new' => true];
    } catch (Throwable $e) {
        error_log('oauth register fail: ' . $e->getMessage());
        return ['ok' => false, 'msg' => '注册失败，请稍后重试', 'user' => null, 'new' => false];
    }
}

/** 建立本站登录态（与 user/login.php 的密码登录保持一致） */
function wm_oauth_sign_in(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['user_pv'] = (string)($user['pass_version'] ?? '1');
    $_SESSION['user_active'] = time();
    $_SESSION['_csrf'] = wm_random(64);
}