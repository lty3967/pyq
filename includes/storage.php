<?php
/**
 * 多存储驱动
 *
 * 支持：本地 / 阿里云 OSS / 腾讯云 COS / 华为云 OBS / 又拍云 / 七牛云 /
 *       通用 S3 兼容 / WebDAV / OneDrive / OpenList(Alist)
 *
 * 设计要点
 * --------
 * 1. 零依赖：项目本身没有 Composer，全部用 cURL / stream 自己实现签名与请求。
 * 2. 统一接口：test() 连接测试 / put() 上传 / deleteByKey() 删除 / url() 访问地址。
 * 3. 上传链路仍是「先在本地重编码 + 生成缩略图 → 再推送成品到远端 → 删掉本地临时文件」。
 *    远端不可用时自动回退保留本地文件（并写错误日志），不会让站点彻底发不出图。
 * 4. 远端文件的 media.path 存「完整访问 URL」，本地文件仍存相对路径；
 *    wm_file_url() / wm_safe_*_path() 已兼容两种形态，历史数据无需迁移。
 * 5. 存储地址不做内网过滤：WebDAV / OpenList / 自建 MinIO 常部署在内网，
 *    这是管理员显式配置的可信目标，与「用户提交任意链接」的抓取场景不同。
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

/**
 * 存储类型清单（后台表单与说明文案都由这里驱动）
 * 字段定义：label 名称 / ph 占位符 / hint 提示 / req 必填 / secret 敏感字段
 * @return array<string,array>
 */
function wm_storage_types(): array
{
    return [
        'local' => [
            'name'  => '本地存储',
            'intro' => '文件保存在服务器 uploads 目录下，由 Nginx / Apache 直接提供访问。不需要任何额外配置，速度受磁盘与带宽限制，适合小流量站点。',
            'url'   => '',
            'fields'=> [],
        ],
        'oss' => [
            'name'  => '阿里云 OSS',
            'intro' => '阿里云对象存储服务。先在阿里云创建 Bucket（建议公共读，并绑定自定义域名或 CDN），再把 Endpoint、Bucket、AccessKey 填入下方。',
            'url'   => 'https://www.aliyun.com/product/oss',
            'fields'=> [
                'endpoint' => ['label' => 'Endpoint', 'ph' => 'oss-cn-hangzhou.aliyuncs.com', 'req' => true, 'hint' => '不含桶名，形如 oss-cn-hangzhou.aliyuncs.com；可在 OSS 控制台「Endpoint」处查看。'],
                'bucket'   => ['label' => 'Bucket 名称', 'req' => true, 'hint' => '即 OSS 控制台里的 Bucket 名称（全部小写）。'],
                'ak'       => ['label' => 'AccessKey ID', 'req' => true],
                'sk'       => ['label' => 'AccessKey Secret', 'req' => true, 'secret' => true, 'hint' => '需具备 PutObject / DeleteObject 权限，加密存储、后台不回显。'],
                'prefix'   => ['label' => '存储路径前缀', 'ph' => 'uploads', 'hint' => '选填。文件实际保存在「前缀/上传相对路径」，便于与其他业务共用 Bucket。'],
                'domain'   => ['label' => '自定义访问域名', 'ph' => 'https://cdn.example.com', 'hint' => '选填。填写后媒体地址用该域名，适合挂 CDN；留空则用 OSS 默认域名。'],
            ],
        ],
        'cos' => [
            'name'  => '腾讯云 COS',
            'intro' => '腾讯云对象存储。先在 COS 控制台创建存储桶（Bucket 名带 APPID），再填地域、SecretId / SecretKey。',
            'url'   => 'https://cloud.tencent.com/product/cos',
            'fields'=> [
                'region'  => ['label' => '地域', 'ph' => 'ap-guangzhou', 'req' => true, 'hint' => '形如 ap-guangzhou / ap-shanghai / ap-beijing。'],
                'bucket'  => ['label' => '存储桶名称（含 APPID）', 'ph' => 'pyq-1250000000', 'req' => true, 'hint' => '形如 mybucket-1250000000。'],
                'ak'      => ['label' => 'SecretId', 'req' => true],
                'sk'      => ['label' => 'SecretKey', 'req' => true, 'secret' => true, 'hint' => '需具备 PutObject / DeleteObject 权限，加密存储、后台不回显。'],
                'prefix'  => ['label' => '存储路径前缀', 'ph' => 'uploads', 'hint' => '选填。'],
                'domain'  => ['label' => '自定义访问域名', 'ph' => 'https://cdn.example.com', 'hint' => '选填。留空则用 COS 默认域名。'],
            ],
        ],
        'obs' => [
            'name'  => '华为云 OBS',
            'intro' => '华为云对象存储服务。创建桶后填写桶所在区域的 Endpoint、Bucket 与 AK/SK。',
            'url'   => 'https://www.huaweicloud.com/product/obs.html',
            'fields'=> [
                'endpoint' => ['label' => 'Endpoint', 'ph' => 'obs.cn-north-4.myhuaweicloud.com', 'req' => true, 'hint' => '不含桶名，形如 obs.cn-north-4.myhuaweicloud.com。'],
                'bucket'   => ['label' => '桶名称', 'req' => true],
                'ak'       => ['label' => 'Access Key ID', 'req' => true],
                'sk'       => ['label' => 'Secret Access Key', 'req' => true, 'secret' => true, 'hint' => '需具备 PutObject / DeleteObject 权限，加密存储、后台不回显。'],
                'prefix'   => ['label' => '存储路径前缀', 'ph' => 'uploads', 'hint' => '选填。'],
                'domain'   => ['label' => '自定义访问域名', 'ph' => 'https://cdn.example.com', 'hint' => '选填。留空则用 OBS 默认域名。'],
            ],
        ],
        'upyun' => [
            'name'  => '又拍云 UPYUN',
            'intro' => '又拍云对象存储 / 加速云。在「又拍云开发者中心」创建服务与操作员，拿到服务名、操作员名与操作员密码（令牌）。',
            'url'   => 'https://www.upyun.com/',
            'fields'=> [
                'service' => ['label' => '服务名（Bucket）', 'req' => true, 'hint' => '又拍云上的「服务」名称。'],
                'operator'=> ['label' => '操作员名', 'req' => true],
                'passwd'  => ['label' => '操作员密码 / 令牌', 'req' => true, 'secret' => true, 'hint' => '在操作员管理页生成，加密存储、后台不回显。'],
                'api'     => ['label' => 'API 节点', 'ph' => 'v0.api.upyun.com', 'hint' => '一般不用改；如账号被分配了专属 API 节点请按控制台填写。'],
                'prefix'  => ['label' => '存储路径前缀', 'ph' => 'pyq', 'hint' => '选填，服务名下的第一级目录。'],
                'domain'  => ['label' => '绑定域名', 'ph' => 'https://cdn.example.com', 'hint' => '选填。绑定自定义域名后可获得 CDN 加速。'],
            ],
        ],
        'qiniu' => [
            'name'  => '七牛云 Kodo',
            'intro' => '七牛云对象存储。创建空间后使用「密钥管理」中的 AK / SK，并绑定或使用默认 CDN 域名。',
            'url'   => 'https://www.qiniu.com/products/kodo',
            'fields'=> [
                'bucket' => ['label' => '空间名（Bucket）', 'req' => true],
                'ak'     => ['label' => 'AccessKey', 'req' => true],
                'sk'     => ['label' => 'SecretKey', 'req' => true, 'secret' => true, 'hint' => '需具备「上传」与「管理」权限，加密存储、后台不回显。'],
                'region' => ['label' => '存储区域', 'ph' => 'z0', 'hint' => '华东 z0、华北 z1、华南 z2、北美 na0、东南亚 as0。'],
                'prefix' => ['label' => '存储路径前缀', 'ph' => 'uploads', 'hint' => '选填。'],
                'domain' => ['label' => '自定义 CDN 域名', 'ph' => 'https://cdn.example.com', 'hint' => '选填。留空则按空间区域使用七牛默认 CDN 域名。'],
            ],
        ],
        's3' => [
            'name'  => '通用 S3 兼容',
            'intro' => '兼容 S3 协议的对象存储：AWS S3、Cloudflare R2、MinIO、阿里云 OSS 兼容模式、天翼云对象存储等均可使用。',
            'url'   => 'https://docs.aws.amazon.com/zh_cn/AmazonS3/latest/userguide/Welcome.html',
            'fields'=> [
                'endpoint' => ['label' => 'Endpoint', 'ph' => 's3.us-east-1.amazonaws.com', 'req' => true, 'hint' => '服务地址。AWS 填 s3.<region>.amazonaws.com；MinIO 填 host:9000。'],
                'bucket'   => ['label' => 'Bucket', 'req' => true],
                'region'   => ['label' => 'Region', 'ph' => 'us-east-1', 'req' => true, 'hint' => '签名用。MinIO 一般填 us-east-1；R2 填 auto。'],
                'ak'       => ['label' => 'Access Key ID', 'req' => true],
                'sk'       => ['label' => 'Secret Access Key', 'req' => true, 'secret' => true, 'hint' => '加密存储、后台不回显。'],
                'style'    => ['label' => '寻址方式', 'type' => 'select', 'opts' => ['path' => '路径风格（MinIO / R2 常用）', 'virtual' => '虚拟主机风格（AWS 默认）'], 'def' => 'path'],
                'prefix'   => ['label' => '存储路径前缀', 'ph' => 'uploads', 'hint' => '选填。'],
                'domain'   => ['label' => '自定义访问域名', 'ph' => 'https://cdn.example.com', 'hint' => '选填。留空则用 Endpoint 直连地址。'],
            ],
        ],
        'webdav' => [
            'name'  => 'WebDAV',
            'intro' => '把文件存到任何支持 WebDAV 的空间：群晖 / 威联通 NAS、坚果云、Nextcloud、以及各家云盘 WebDAV 服务。适合文件需要留在自有服务器的场景。注意：浏览器加载图片不会携带用户名密码，请确保该目录已配置为公开可读（或使用其公开分享地址），否则前台图片无法显示。',
            'url'   => 'https://developer.mozilla.org/zh-CN/docs/Glossary/WebDAV',
            'fields'=> [
                'url'    => ['label' => '服务器地址', 'ph' => 'https://dav.example.com/remote.php/dav/files/xxx/pyq', 'req' => true, 'hint' => '目录地址，需带协议；请确认浏览器能直接访问该地址。'],
                'user'   => ['label' => '用户名', 'req' => true],
                'pass'   => ['label' => '密码 / 应用密码', 'req' => true, 'secret' => true, 'hint' => '加密存储、后台不回显。坚果云等需使用「应用密码」。'],
                'prefix' => ['label' => '子目录', 'ph' => 'uploads', 'hint' => '选填，会在服务器地址下再创建一级目录。'],
            ],
        ],
        'onedrive' => [
            'name'  => 'OneDrive',
            'intro' => '文件存到 Microsoft OneDrive。需要 Azure 应用（客户端 ID + 客户端密钥）并授予 Files.ReadWrite 离线访问权限，用 refresh_token 换取 access_token 后即可上传。注意：OneDrive 文件默认私有，本系统保存的是约 1 小时有效的直链，过期后旧图片会失效，因此适合个人备份或测试，长期公开展示建议改用对象存储（OSS / COS / S3 等）。',
            'url'   => 'https://www.microsoft.com/zh-cn/microsoft-365/onedrive/online-cloud-storage',
            'fields'=> [
                'tenant'  => ['label' => '租户 / 站点', 'ph' => 'contoso.onmicrosoft.com', 'req' => true, 'hint' => '个人账号可填 common。用于换取 access_token。'],
                'cid'     => ['label' => '客户端 ID', 'req' => true, 'hint' => 'Azure 应用（App registration）的 Application (client) ID。'],
                'csecret' => ['label' => '客户端密钥', 'req' => true, 'secret' => true, 'hint' => 'Azure 应用的 Client secret，加密存储、后台不回显。'],
                'refresh' => ['label' => 'Refresh Token', 'hint' => '带 offline_access 授权后拿到的长期 refresh_token，失效后需重新获取（若已直接填写 Access Token 可留空）。'],
                'token'   => ['label' => 'Access Token', 'hint' => '选填。填写后直接使用该令牌，不再用 refresh_token 换取（一般留空）。'],
                'drive'   => ['label' => '上传目录', 'ph' => 'pyq', 'hint' => '选填，OneDrive 中的目录名，留空表示根目录。'],
            ],
        ],
        'openlist' => [
            'name'  => 'OpenList / Alist',
            'intro' => '把文件挂到 OpenList（原 Alist）上，由 OpenList 转发到各家网盘。支持挂载阿里云盘、115、夸克等，适合不想再单独买对象存储的场景。',
            'url'   => 'https://doc.oplist.org/',
            'fields'=> [
                'url'    => ['label' => '服务地址', 'ph' => 'https://alist.example.com', 'req' => true, 'hint' => 'OpenList / Alist 的站点根地址。'],
                'user'   => ['label' => '用户名', 'hint' => 'OpenList 管理员账号；已填写 API Token 时可留空。'],
                'pass'   => ['label' => '密码', 'secret' => true, 'hint' => '加密存储、后台不回显。已填写 API Token 时可留空。'],
                'token'  => ['label' => 'API Token', 'hint' => '选填。填写后优先使用该令牌（OpenList 用户 → 令牌），此时可跳过用户名密码。'],
                'prefix' => ['label' => '上传目录', 'ph' => '/pyq', 'hint' => '以 / 开头的目录路径，不存在会自动创建。'],
            ],
        ],
    ];
}

/** 某类型的敏感字段列表 */
function wm_storage_secret_fields(string $type): array
{
    $types = wm_storage_types();
    if (!isset($types[$type])) {
        return [];
    }
    $out = [];
    foreach ($types[$type]['fields'] as $k => $f) {
        if (!empty($f['secret'])) {
            $out[] = $k;
        }
    }
    return $out;
}

// ============================================================================
// 配置读写
// ============================================================================

/** 当前存储类型 */
function wm_storage_type(): string
{
    $t = (string)wm_setting('storage_type', 'local');
    return isset(wm_storage_types()[$t]) ? $t : 'local';
}

/** 读取当前类型的配置（敏感字段已解密，供驱动直接使用） */
function wm_storage_cfg(): array
{
    $json = (string)wm_setting('storage_opts', '');
    $raw = json_decode($json, true);
    if (!is_array($raw)) {
        $raw = [];
    }
    $type = wm_storage_type();
    $types = wm_storage_types();
    $secret = wm_storage_secret_fields($type);
    $out = [];
    foreach ($types[$type]['fields'] as $k => $f) {
        $v = $raw[$k] ?? ($f['def'] ?? '');
        if (!is_scalar($v)) {
            $v = '';
        }
        $v = (string)$v;
        $out[$k] = in_array($k, $secret, true) ? wm_secret_decode($v) : trim($v);
    }
    return $out;
}

/**
 * 保存配置：敏感字段留空表示不修改
 * @return array{ok:bool,msg:string}
 */
function wm_storage_save(string $type, array $in): array
{
    $types = wm_storage_types();
    if (!isset($types[$type])) {
        return ['ok' => false, 'msg' => '未知的存储类型'];
    }
    // 本地存储没有参数：只切换类型，保留已填写的云存储配置，
    // 以便临时切回本地排查问题时不必重新录入密钥。
    if ($type === 'local') {
        wm_setting_set('storage_type', 'local');
        return ['ok' => true, 'msg' => 'ok'];
    }
    $old = json_decode((string)wm_setting('storage_opts', ''), true);
    if (!is_array($old)) {
        $old = [];
    }

    $cur = [];
    foreach ($types[$type]['fields'] as $k => $f) {
        $val = isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
        $isSecret = !empty($f['secret']);
        if ($isSecret) {
            if ($val !== '') {
                $cur[$k] = wm_secret_encode($val);
            } elseif (isset($old[$k])) {
                $cur[$k] = (string)$old[$k]; // 留空 = 不修改
            } else {
                $cur[$k] = '';
            }
        } else {
            $cur[$k] = $val;
        }
        if (!empty($f['req']) && wm_secret_decode((string)$cur[$k]) === '') {
            return ['ok' => false, 'msg' => '请填写「' . $f['label'] . '」'];
        }
    }
    if ($type !== 'local' && wm_secret_decode((string)($cur['ak'] ?? '')) === '' && isset($types[$type]['fields']['ak'])) {
        return ['ok' => false, 'msg' => '请填写「AccessKey」类凭据'];
    }

    wm_setting_set('storage_type', $type);
    wm_setting_set('storage_opts', (string)json_encode($cur, JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'msg' => 'ok'];
}

// ============================================================================
// HTTP 底座
// ============================================================================

/**
 * 通用 HTTP 请求（cURL 优先，降级 stream）
 * @param string $bodyData 字符串请求体（与 $bodyFile 二选一）
 * @param string $bodyFile 文件请求体（大文件必用，不进内存）
 * @return array{ok:bool,code:int,msg:string,body:string,headers:string}
 */
function wm_storage_http(string $method, string $url, array $headers = [], string $bodyData = '', string $bodyFile = ''): array
{
    if (!function_exists('curl_init')) {
        return wm_storage_http_stream($method, $url, $headers, $bodyData, $bodyFile);
    }
    $method = strtoupper($method);
    $fp = null;
    if ($bodyFile !== '') {
        $fp = @fopen($bodyFile, 'rb');
        if ($fp === false) {
            return ['ok' => false, 'code' => 0, 'msg' => '无法读取待上传文件', 'body' => '', 'headers' => ''];
        }
    }
    $ch = curl_init($url);
    if ($ch === false) {
        if (is_resource($fp)) { fclose($fp); }
        return ['ok' => false, 'code' => 0, 'msg' => 'cURL 初始化失败', 'body' => '', 'headers' => ''];
    }
    $respHeaders = [];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'pyq-storage/1.0',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders): int {
            $respHeaders[] = rtrim($line, "\r\n");
            return strlen($line);
        },
    ];
    if ($fp !== null) {
        // 以 PUT / MKCOL 等带 body 的方式上传文件，避免整文件进内存
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        $opts[CURLOPT_UPLOAD]       = true;
        $opts[CURLOPT_INFILE]       = $fp;
        $opts[CURLOPT_INFILESIZE]   = (int)filesize($bodyFile);
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($bodyData !== '') {
            $opts[CURLOPT_POSTFIELDS] = $bodyData;
        }
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (is_resource($fp)) {
        fclose($fp);
    }
    if ($body === false) {
        return ['ok' => false, 'code' => $code, 'msg' => $err !== '' ? $err : '网络请求失败', 'body' => '', 'headers' => ''];
    }
    $isOk = $code >= 200 && $code < 300;
    return [
        'ok'      => $isOk,
        'code'    => $code,
        'msg'     => $isOk ? 'ok' : ('HTTP ' . $code . '：' . wm_cut(trim((string)$body), 160)),
        'body'    => (string)$body,
        'headers' => implode("\n", $respHeaders),
    ];
}

/**
 * 上传文件并在内容前追加固定前缀（用于又拍云表单上传的 file=xxx）。
 * 用 cURL 读回调流式发送，不会把整个视频读进内存。
 * @return array{ok:bool,code:int,msg:string,body:string,headers:string}
 */
function wm_storage_http_prefixed(string $method, string $url, array $headers, string $file, string $prefix): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'code' => 0, 'msg' => '服务器未启用 cURL 扩展，无法使用该存储类型', 'body' => '', 'headers' => ''];
    }
    $size = @filesize($file);
    if ($size === false) {
        return ['ok' => false, 'code' => 0, 'msg' => '无法读取待上传文件', 'body' => '', 'headers' => ''];
    }
    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        return ['ok' => false, 'code' => 0, 'msg' => '无法读取待上传文件', 'body' => '', 'headers' => ''];
    }
    $sent = false;
    $ch = curl_init($url);
    if ($ch === false) {
        fclose($fp);
        return ['ok' => false, 'code' => 0, 'msg' => 'cURL 初始化失败', 'body' => '', 'headers' => ''];
    }
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'pyq-storage/1.0',
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_UPLOAD         => true,
        CURLOPT_INFILE         => $fp,
        CURLOPT_INFILESIZE     => (int)$size + strlen($prefix),
        CURLOPT_READFUNCTION   => static function ($ch, $fd, $len) use ($fp, $prefix, &$sent) {
            if (!$sent) {
                $sent = true;
                return $prefix;
            }
            $chunk = fread($fp, max(8192, $len));
            return $chunk === false ? '' : $chunk;
        },
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders): int {
            $respHeaders[] = rtrim($line, "\r\n");
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);
    if ($body === false) {
        return ['ok' => false, 'code' => $code, 'msg' => $err !== '' ? $err : '网络请求失败', 'body' => '', 'headers' => ''];
    }
    $isOk = $code >= 200 && $code < 300;
    return [
        'ok'      => $isOk,
        'code'    => $code,
        'msg'     => $isOk ? 'ok' : ('HTTP ' . $code . '：' . wm_cut(trim((string)$body), 160)),
        'body'    => (string)$body,
        'headers' => implode("\n", $respHeaders),
    ];
}

/** 无 cURL 环境下的降级实现 */
function wm_storage_http_stream(string $method, string $url, array $headers, string $bodyData, string $bodyFile): array
{
    if ($bodyFile !== '') {
        return ['ok' => false, 'code' => 0, 'msg' => '服务器未启用 cURL 扩展，无法上传大文件，请改用本地存储', 'body' => '', 'headers' => ''];
    }
    $method = strtoupper($method);
    $lines = [];
    foreach ($headers as $k => $v) {
        $lines[] = is_int($k) ? $v : ($k . ': ' . $v);
    }
    $ctx = [
        'http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $lines),
            'content'       => $bodyData,
            'timeout'       => 30,
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
            'user_agent'    => 'pyq-storage/1.0',
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ];
    $body = @file_get_contents($url, false, stream_context_create($ctx));
    $code = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
            $code = (int)$m[1];
        }
    }
    if ($body === false) {
        return ['ok' => false, 'code' => $code, 'msg' => '网络请求失败（服务器可能禁用了 allow_url_fopen）', 'body' => '', 'headers' => ''];
    }
    $isOk = $code >= 200 && $code < 300;
    return [
        'ok'      => $isOk,
        'code'    => $code,
        'msg'     => $isOk ? 'ok' : ('HTTP ' . $code . '：' . wm_cut(trim((string)$body), 160)),
        'body'    => (string)$body,
        'headers' => '',
    ];
}

/** 路径分段编码（保留 /） */
function wm_storage_encode_path(string $path): string
{
    $out = [];
    foreach (explode('/', ltrim($path, '/')) as $seg) {
        $out[] = rawurlencode($seg);
    }
    return implode('/', $out);
}

/** 主机名 + 端口（自动处理 IPv6 字面量） */
function wm_storage_host(string $host, int $port): string
{
    $default = ($port === 80) || ($port === 443);
    if (strpos($host, ':') !== false) {
        return '[' . trim($host, '[]') . ']' . ($default ? '' : ':' . $port);
    }
    return $host . ($default ? '' : ':' . $port);
}

/**
 * 解析 endpoint（允许省略协议、含端口或含路径）
 * @return array{scheme:string,host:string,port:int,origin:string} host 不含端口
 */
function wm_storage_endpoint(string $endpoint, int $defaultPort, string $defaultScheme = 'https'): array
{
    $endpoint = trim($endpoint);
    $scheme = $defaultScheme;
    if (preg_match('#^(https?)://#i', $endpoint, $m)) {
        $scheme = strtolower($m[1]);
        $endpoint = substr($endpoint, strlen($m[0]));
    }
    $endpoint = trim($endpoint, '/');
    $host = $endpoint;
    $port = $defaultPort;
    if (preg_match('#^\[(.+)\](?::(\d+))?$#', $endpoint, $m)) {
        $host = $m[1];
        $port = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : $defaultPort;
    } elseif (substr_count($endpoint, ':') === 1) {
        [$h, $p] = explode(':', $endpoint, 2);
        if (preg_match('/^\d{1,5}$/', $p)) {
            $host = $h;
            $port = (int)$p;
        }
    }
    return [
        'scheme' => $scheme,
        'host'   => $host,
        'port'   => $port,
        'origin' => $scheme . '://' . wm_storage_host($host, $port),
    ];
}

// ============================================================================
// 驱动基类
// ============================================================================

/**
 * @property-read array $cfg
 */
abstract class WmStorageBase
{
    /** @var array<string,string> */
    protected $cfg = [];

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    protected function c(string $k, string $d = ''): string
    {
        $v = $this->cfg[$k] ?? '';
        return is_scalar($v) && (string)$v !== '' ? (string)$v : $d;
    }

    /** 前缀（无前后斜杠） */
    protected function prefix(): string
    {
        return trim($this->c('prefix'), "/ \t\n\r\0\x0B");
    }

    /** 相对路径 → 对象键 */
    public function key(string $relPath): string
    {
        $rel = ltrim(str_replace('\\', '/', $relPath), '/');
        $p = $this->prefix();
        return $p === '' ? $rel : $p . '/' . $rel;
    }

    /** 访问地址前缀（用于从 URL 反解对象键） */
    abstract public function urlPrefix(): string;

    /** 从访问地址反解对象键，无法识别返回 '' */
    public function keyFromUrl(string $url): string
    {
        $prefix = $this->urlPrefix();
        if ($prefix === '' || strpos($url, $prefix) !== 0) {
            return '';
        }
        $key = substr($url, strlen($prefix));
        return $key === '' ? '' : ltrim(rawurldecode($key), '/');
    }

    // 注意：put() 不声明为抽象方法。PHP 不允许在同一个类里先 abstract 声明、
    // 再用同名具体方法实现（会抛 Cannot redeclare），而它对所有驱动都是同一套
    // 「相对路径 → 对象键 → 委托 putObject」逻辑，直接在类尾部给具体实现即可。

    abstract public function deleteByKey(string $key): bool;

    /** 连接测试：上传一个探测文件再删掉 */
    public function test(): array
    {
        $key = $this->key('wm-test/' . date('YmdHis') . '_' . wm_random(4) . '.txt');
        $tmp = $this->tmpFile('wm-storage-test: ' . WM_VERSION . ' @ ' . date('Y-m-d H:i:s'));
        if ($tmp === '') {
            return ['ok' => false, 'msg' => '无法创建临时探测文件，请检查 PHP 临时目录是否可写'];
        }
        try {
            $r = $this->putObject($key, $tmp, 'text/plain');
            if (!$r['ok']) {
                return ['ok' => false, 'msg' => '上传探测文件失败：' . $r['msg']];
            }
            $deleted = $this->deleteByKey($key);
            return [
                'ok'  => true,
                'msg' => '连接成功：读写均正常（访问地址 ' . wm_cut($r['url'], 120) . '，探测文件' . ($deleted ? '已清理' : '未能删除，请在存储后台手动清理 wm-test/ 目录') . '）',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'msg' => '连接测试异常：' . $e->getMessage()];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * 上传任意本地文件到对象键（子类实现）
     * @return array{ok:bool,msg:string,url:string}
     */
    abstract public function putObject(string $key, string $localFile, string $mime): array;

    /** 上传字符串内容到对象键 */
    public function putString(string $key, string $content, string $mime = 'text/plain'): array
    {
        $tmp = $this->tmpFile($content);
        if ($tmp === '') {
            return ['ok' => false, 'msg' => '无法创建临时文件', 'url' => ''];
        }
        try {
            return $this->putObject($key, $tmp, $mime);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** 写临时文件，返回绝对路径 */
    protected function tmpFile(string $content): string
    {
        $dir = WM_DATA . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return '';
        }
        $p = $dir . '/' . wm_random(10) . '.tmp';
        return @file_put_contents($p, $content) === false ? '' : $p;
    }

    /** 从对象键生成访问地址（子类可覆盖） */
    public function url(string $key): string
    {
        return $this->urlPrefix() . wm_storage_encode_path($key);
    }

    /**
     * 统一入口：把本地成品推到远端（所有驱动逻辑一致，故基类直接给实现）
     * @return array{ok:bool,msg:string,url:string}
     */
    public function put(string $relPath, string $localFile, string $mime): array
    {
        return $this->putObject($this->key($relPath), $localFile, $mime);
    }
}

// ============================================================================
// 本地存储（占位，用于「切换到本地」时清空旧配置）
// ============================================================================

class WmStorageLocal extends WmStorageBase
{
    public function urlPrefix(): string
    {
        return '';
    }
    public function putObject(string $key, string $localFile, string $mime): array
    {
        return ['ok' => false, 'msg' => '本地存储不可用此接口', 'url' => ''];
    }
    public function deleteByKey(string $key): bool
    {
        return false;
    }
    public function test(): array
    {
        $dir = WM_UPLOAD;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            return ['ok' => false, 'msg' => 'uploads 目录不可写，请检查目录权限（建议 755 或 775）'];
        }
        $p = $dir . '/wm_write_test_' . wm_random(4) . '.tmp';
        $ok = @file_put_contents($p, 'ok') !== false;
        if ($ok) {
            @unlink($p);
        }
        $free = @disk_free_space($dir);
        return [
            'ok'  => $ok,
            'msg' => $ok
                ? ('本地存储可用：uploads 目录可写，剩余空间约 ' . wm_size((float)$free))
                : 'uploads 目录不可写',
        ];
    }
}

// ============================================================================
// S3 协议族（通用 S3 / 阿里云 OSS 之外都用它）
// ============================================================================

/**
 * AWS Signature V4 签名器（同时被「通用 S3 兼容」与「华为云 OBS」复用，
 * OBS 使用同样的 v4 算法，只是 service 名为 obs、Endpoint 不同）
 */
trait WmStorageV4
{
    /**
     * 发起一次 AWS V4 签名请求
     * @param string $payloadHash 请求体的 sha256（空串请传 hash('sha256','')）
     * @param string $file        待上传的本地文件；为空表示无请求体
     */
    protected function v4Request(string $method, string $url, string $service, string $region, string $ak, string $sk, string $payloadHash, array $extraHeaders = [], string $file = ''): array
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['host'])) {
            return ['ok' => false, 'code' => 0, 'msg' => 'Endpoint 解析失败', 'body' => '', 'headers' => ''];
        }
        $scheme = strtolower((string)($p['scheme'] ?? 'https'));
        $host = (string)$p['host'];
        $port = isset($p['port']) ? (int)$p['port'] : ($scheme === 'https' ? 443 : 80);
        $path = (string)($p['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = (string)($p['query'] ?? '');

        $amzDate = gmdate('Ymd\THis\Z');
        $dateOnly = substr($amzDate, 0, 8);

        $headers = [
            'host' => wm_storage_host($host, $port),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        foreach ($extraHeaders as $k => $v) {
            $headers[strtolower((string)$k)] = (string)$v;
        }
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . str_replace(["\r", "\n"], '', trim((string)$v)) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = strtoupper($method) . "\n" . $path . "\n" . $query . "\n"
            . $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;
        $scope = $dateOnly . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $amzDate . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateOnly, 'AWS4' . $sk, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = bin2hex(hash_hmac('sha256', $stringToSign, $kSigning, true));

        $out = [];
        foreach ($headers as $k => $v) {
            $out[$k] = (string)$v;
        }
        $out['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $ak . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        return wm_storage_http(strtoupper($method), $url, $out, '', $file);
    }
}

class WmStorageS3 extends WmStorageBase
{
    use WmStorageV4;

    private function region(): string
    {
        return $this->c('region', 'us-east-1');
    }

    private function origin(): array
    {
        return wm_storage_endpoint($this->c('endpoint'), 443, 'https');
    }

    /** 完整对象 URL */
    private function objectUrl(string $key): string
    {
        $ep = $this->origin();
        $bucket = $this->c('bucket');
        $path = '/' . wm_storage_encode_path($key);
        if ($this->c('style', 'path') === 'virtual') {
            return $ep['scheme'] . '://' . wm_storage_host($bucket . '.' . $ep['host'], $ep['port']) . $path;
        }
        return $ep['scheme'] . '://' . wm_storage_host($ep['host'], $ep['port'])
            . '/' . wm_storage_encode_path($bucket) . $path;
    }

    public function urlPrefix(): string
    {
        $d = trim($this->c('domain'), '/');
        if ($d !== '') {
            return (preg_match('#^https?://#i', $d) ? $d : 'https://' . $d) . '/';
        }
        $ep = $this->origin();
        $bucket = $this->c('bucket');
        if ($this->c('style', 'path') === 'virtual') {
            return $ep['scheme'] . '://' . wm_storage_host($bucket . '.' . $ep['host'], $ep['port']) . '/';
        }
        return $ep['scheme'] . '://' . wm_storage_host($ep['host'], $ep['port']) . '/' . $bucket . '/';
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $hash = hash_file('sha256', $localFile);
        if ($hash === false) {
            return ['ok' => false, 'msg' => '读取文件失败', 'url' => ''];
        }
        // 不把 content-length 纳入签名：交给 cURL 生成，避免与签名值不一致被拒
        $r = $this->v4Request('PUT', $this->objectUrl($key), 's3', $this->region(),
            $this->c('ak'), $this->c('sk'), $hash, [], $localFile);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
    }

    public function deleteByKey(string $key): bool
    {
        $hash = hash('sha256', '');
        $r = $this->v4Request('DELETE', $this->objectUrl($key), 's3', $this->region(),
            $this->c('ak'), $this->c('sk'), $hash);
        return (bool)$r['ok'];
    }
}

class WmStorageObs extends WmStorageBase
{
    use WmStorageV4;

    private function region(): string
    {
        // Endpoint 形如 obs.cn-north-4.myhuaweicloud.com → 区域取 cn-north-4
        $ep = $this->c('endpoint');
        if (preg_match('#\b([a-z]{2}-[a-z]+-\d+)\.#i', $ep, $m)) {
            return strtolower($m[1]);
        }
        return 'us-east-1';
    }

    private function objectUrl(string $key): string
    {
        $ep = wm_storage_endpoint($this->c('endpoint'), 443, 'https');
        return $ep['origin'] . '/' . wm_storage_encode_path($this->c('bucket')) . '/' . wm_storage_encode_path($key);
    }

    public function urlPrefix(): string
    {
        $d = trim($this->c('domain'), '/');
        if ($d !== '') {
            return (preg_match('#^https?://#i', $d) ? $d : 'https://' . $d) . '/';
        }
        $ep = wm_storage_endpoint($this->c('endpoint'), 443, 'https');
        return $ep['origin'] . '/' . $this->c('bucket') . '/';
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $hash = hash_file('sha256', $localFile);
        if ($hash === false) {
            return ['ok' => false, 'msg' => '读取文件失败', 'url' => ''];
        }
        $r = $this->v4Request('PUT', $this->objectUrl($key), 'obs', $this->region(),
            $this->c('ak'), $this->c('sk'), $hash, [], $localFile);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
    }

    public function deleteByKey(string $key): bool
    {
        $r = $this->v4Request('DELETE', $this->objectUrl($key), 'obs', $this->region(),
            $this->c('ak'), $this->c('sk'), hash('sha256', ''));
        return (bool)$r['ok'];
    }
}

// ============================================================================
// 阿里云 OSS（V1 签名，Authorization: OSS <ak>:<sig>）
// ============================================================================

class WmStorageOss extends WmStorageBase
{
    private function ep(): array
    {
        // 未写协议时默认 https（OSS 两个端口都支持，https 更安全）
        return wm_storage_endpoint($this->c('endpoint'), 443, 'https');
    }

    private function objectUrl(string $key): string
    {
        $ep = $this->ep();
        $bucket = $this->c('bucket');
        // 虚拟主机风格：https://bucket.endpoint/key（阿里云标准用法）
        return $ep['scheme'] . '://' . wm_storage_host($bucket . '.' . $ep['host'], $ep['port'])
            . '/' . wm_storage_encode_path($key);
    }

    /** 资源路径：/bucket/key */
    private function resource(string $key): string
    {
        return '/' . $this->c('bucket') . '/' . $key;
    }

    private function sign(string $method, string $key, string $contentType, string $date): string
    {
        $str = $method . "\n" . "\n" . $contentType . "\n" . $date . "\n" . $this->resource($key);
        return base64_encode(hash_hmac('sha1', $str, $this->c('sk'), true));
    }

    public function urlPrefix(): string
    {
        $d = trim($this->c('domain'), '/');
        if ($d !== '') {
            return (preg_match('#^https?://#i', $d) ? $d : 'https://' . $d) . '/';
        }
        $ep = $this->ep();
        return $ep['scheme'] . '://' . $this->c('bucket') . '.' . $ep['host'] . '/';
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $ct = $mime !== '' ? $mime : 'application/octet-stream';
        $url = $this->objectUrl($key);
        $r = wm_storage_http('PUT', $url, [
            'Date: ' . $date,
            'Content-Type: ' . $ct,
            'Content-Length: ' . filesize($localFile),
            'Authorization: OSS ' . $this->c('ak') . ':' . $this->sign('PUT', $key, $ct, $date),
        ], '', $localFile);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
    }

    public function deleteByKey(string $key): bool
    {
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $url = $this->objectUrl($key);
        $r = wm_storage_http('DELETE', $url, [
            'Date: ' . $date,
            'Authorization: OSS ' . $this->c('ak') . ':' . $this->sign('DELETE', $key, '', $date),
        ]);
        return (bool)$r['ok'];
    }
}

// ============================================================================
// 腾讯云 COS（q-sign-algorithm=sha1 签名）
// ============================================================================

class WmStorageCos extends WmStorageBase
{
    private function host(): string
    {
        return $this->c('bucket') . '.cos.' . $this->c('region') . '.myqcloud.com';
    }

    private function objectUrl(string $key): string
    {
        return 'https://' . $this->host() . '/' . wm_storage_encode_path($key);
    }

    /** 生成带签名的 query */
    private function signedQuery(string $method, string $key, int $ttl = 600): string
    {
        $ak = $this->c('ak');
        $sk = $this->c('sk');
        $keyTime = time() . ';' . (time() + $ttl);
        $signKey = hash_hmac('sha1', $keyTime, $sk, true);
        // 未签任何 header 与 query 时的固定骨架
        $httpString = strtoupper($method) . "\n" . '/' . $key . "\n" . "\n" . "\n";
        $stringToSign = "sha1\n" . $keyTime . "\n" . hash('sha1', $httpString);
        $signature = hash_hmac('sha1', $stringToSign, $signKey);
        return http_build_query([
            'q-sign-algorithm' => 'sha1',
            'q-ak' => $ak,
            'q-sign-time' => $keyTime,
            'q-key-time' => $keyTime,
            'q-header-list' => '',
            'q-url-param-list' => '',
            'q-signature' => $signature,
        ]);
    }

    public function urlPrefix(): string
    {
        $d = trim($this->c('domain'), '/');
        if ($d !== '') {
            return (preg_match('#^https?://#i', $d) ? $d : 'https://' . $d) . '/';
        }
        return 'https://' . $this->host() . '/';
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $ct = $mime !== '' ? $mime : 'application/octet-stream';
        $url = $this->objectUrl($key) . '?' . $this->signedQuery('PUT', $key);
        $r = wm_storage_http('PUT', $url, [
            'Content-Type: ' . $ct,
            'Content-Length: ' . filesize($localFile),
            'Host: ' . $this->host(),
        ], '', $localFile);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
    }

    public function deleteByKey(string $key): bool
    {
        $url = $this->objectUrl($key) . '?' . $this->signedQuery('DELETE', $key);
        $r = wm_storage_http('DELETE', $url, ['Host: ' . $this->host()]);
        return (bool)$r['ok'];
    }
}

// ============================================================================
// 七牛云（表单上传 + 管理 API 删除）
// ============================================================================

class WmStorageQiniu extends WmStorageBase
{
    private const HOSTS = [
        'z0'  => ['up' => 'upload.qiniup.com',    'cdn' => 'cdn.qiniudn.com'],
        'z1'  => ['up' => 'upload-z1.qiniup.com',  'cdn' => 'cdn-z1.qiniudn.com'],
        'z2'  => ['up' => 'upload-z2.qiniup.com',  'cdn' => 'cdn-z2.qiniudn.com'],
        'na0' => ['up' => 'up-na0.qbox.me',       'cdn' => 'cdn-na0.qbox.me'],
        'as0' => ['up' => 'up-as0.qbox.me',       'cdn' => 'cdn-as0.qbox.me'],
    ];

    private function hosts(): array
    {
        $r = strtolower($this->c('region', 'z0'));
        return self::HOSTS[$r] ?? self::HOSTS['z0'];
    }

    private function policy(): string
    {
        $scope = $this->c('bucket');
        $p = $this->prefix();
        if ($p !== '') {
            $scope .= ':' . $p;
        }
        return json_encode(['scope' => $scope, 'deadline' => time() + 3600], JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function uploadToken(): string
    {
        $sign = base64_encode(hash_hmac('sha1', $this->policy(), $this->c('sk'), true));
        $sign = strtr($sign, '+/', '-_');
        return $this->c('ak') . ':' . rtrim($sign, '=');
    }

    public function urlPrefix(): string
    {
        $d = trim($this->c('domain'), '/');
        if ($d !== '') {
            return (preg_match('#^https?://#i', $d) ? $d : 'https://' . $d) . '/';
        }
        $h = $this->hosts();
        return 'http://' . $this->c('bucket') . '.' . $h['cdn'] . '/';
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $h = $this->hosts();
        $token = $this->uploadToken();
        $url = 'http://' . $h['up'] . '/';

        // 首选 cURL 的 CURLFile：文件由 curl 从磁盘流式读取，不会占满内存
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch !== false) {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => [
                        'token' => $token,
                        'key'   => $key,
                        'file'  => new CURLFile($localFile, $mime !== '' ? $mime : 'application/octet-stream', basename($key)),
                    ],
                    CURLOPT_TIMEOUT        => 300,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT      => 'pyq-storage/1.0',
                ]);
                $body = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if ($body !== false) {
                    if ($code >= 200 && $code < 300) {
                        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
                    }
                    return ['ok' => false, 'msg' => 'HTTP ' . $code . '：' . wm_cut(trim((string)$body), 160), 'url' => ''];
                }
                return ['ok' => false, 'msg' => $err !== '' ? $err : '网络请求失败', 'url' => ''];
            }
        }

        // 降级：手工拼 multipart（整文件进内存，仅适用于体积较小的图片）
        $boundary = '----pyq' . wm_random(16);
        $body = '';
        foreach ([['token', $token], ['key', $key]] as $f) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $f[0] . '"' . "\r\n\r\n" . $f[1] . "\r\n";
        }
        $body .= '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . basename($key) . '"' . "\r\n"
            . 'Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream') . "\r\n\r\n";
        $body .= (string)file_get_contents($localFile) . "\r\n--" . $boundary . "--\r\n";

        $r = wm_storage_http('POST', $url, [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ], $body);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
    }

    public function deleteByKey(string $key): bool
    {
        $path = '/v2/delete';
        $body = json_encode(['bucket' => $this->c('bucket'), 'keys' => [$key]], JSON_UNESCAPED_SLASHES) ?: '{}';
        $sign = base64_encode(hash_hmac('sha1', $path . "\n" . $body, $this->c('sk'), true));
        $sign = strtr($sign, '+/', '-_');
        $auth = 'QBox ' . $this->c('ak') . ':' . rtrim($sign, '=');
        $r = wm_storage_http('POST', 'https://rs.qbox.me' . $path, [
            'Authorization: ' . $auth,
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ], $body);
        return (bool)$r['ok'];
    }
}

// ============================================================================
// 又拍云（表单上传 + DELETE）
// ============================================================================

class WmStorageUpyun extends WmStorageBase
{
    private function apiHost(): string
    {
        $api = trim($this->c('api', 'v0.api.upyun.com'));
        $api = preg_replace('#^https?://#i', '', $api) ?? $api;
        return trim($api, '/');
    }

    /** uri：/服务名/对象路径 */
    private function uri(string $key): string
    {
        $p = $this->prefix();
        $path = '/' . $this->c('service') . ($p !== '' ? '/' . $p : '') . '/' . $key;
        return $path;
    }

    private function authHeaders(string $uri): array
    {
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        // 又拍云签名：sign = base64(hmac_sha1(key=md5(操作员密码), data=Date))
        $sign = base64_encode(hash_hmac('sha1', $date, md5($this->c('passwd')), true));
        return [
            'Date: ' . $date,
            'Authorization: UPYUN ' . $this->c('operator') . ':' . $sign,
        ];
    }

    public function urlPrefix(): string
    {
        $d = trim($this->c('domain'), '/');
        if ($d !== '') {
            return (preg_match('#^https?://#i', $d) ? $d : 'https://' . $d) . '/';
        }
        return 'http://' . $this->c('service') . '.v0.upyun.com/';
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $uri = $this->uri($key);
        $headers = $this->authHeaders($uri);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        // 表单上传格式为 file=<文件内容>，用读回调流式发送，避免整文件进内存
        $r = wm_storage_http_prefixed('PUT', 'https://' . $this->apiHost() . $uri, $headers, $localFile, 'file=');
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
    }

    public function deleteByKey(string $key): bool
    {
        $uri = $this->uri($key);
        $headers = $this->authHeaders($uri);
        $r = wm_storage_http('DELETE', 'https://' . $this->apiHost() . $uri, $headers);
        return (bool)$r['ok'];
    }
}

// ============================================================================
// WebDAV
// ============================================================================

class WmStorageWebdav extends WmStorageBase
{
    private function base(): string
    {
        $u = rtrim($this->c('url'), '/');
        $p = $this->prefix();
        return $p === '' ? $u : $u . '/' . $p;
    }

    public function urlPrefix(): string
    {
        return $this->base() . '/';
    }

    private function auth(): array
    {
        return ['Authorization: Basic ' . base64_encode($this->c('user') . ':' . $this->c('pass'))];
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $path = '/' . wm_storage_encode_path($key);
        $base = $this->base();
        // 逐级 MKCOL 建目录（已存在时服务器返回 405/301，忽略即可）
        $segs = explode('/', trim($path, '/'));
        $cur = '';
        array_pop($segs);
        foreach ($segs as $seg) {
            $cur .= '/' . $seg;
            wm_storage_http('MKCOL', $base . $cur, $this->auth());
        }
        $r = wm_storage_http('PUT', $base . $path, array_merge($this->auth(), [
            'Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'),
            'Content-Length: ' . filesize($localFile),
        ]), '', $localFile);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
    }

    public function deleteByKey(string $key): bool
    {
        $r = wm_storage_http('DELETE', $this->base() . '/' . wm_storage_encode_path($key), $this->auth());
        return (bool)$r['ok'];
    }
}

// ============================================================================
// OneDrive（Microsoft Graph）
// ============================================================================

class WmStorageOnedrive extends WmStorageBase
{
    /** 取 access_token：优先用固定令牌，其次用 refresh_token 换 */
    private function accessToken(): array
    {
        $fixed = $this->c('token');
        if ($fixed !== '') {
            return ['ok' => true, 'msg' => 'ok', 'token' => $fixed];
        }
        $tenant = $this->c('tenant', 'common');
        $r = wm_storage_http('POST', 'https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token', [
            'Content-Type: application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->c('refresh'),
            'client_id'     => $this->c('cid'),
            'client_secret' => $this->c('csecret'),
            'scope'         => 'https://graph.microsoft.com/.default offline_access Files.ReadWrite',
        ]));
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => '获取访问令牌失败：' . $r['msg'], 'token' => ''];
        }
        $j = json_decode((string)$r['body'], true);
        $t = is_array($j) && isset($j['access_token']) ? (string)$j['access_token'] : '';
        if ($t === '') {
            $msg = is_array($j) ? (string)($j['error_description'] ?? $j['error'] ?? '') : '';
            return ['ok' => false, 'msg' => '获取访问令牌失败：' . ($msg !== '' ? $msg : '返回中无 access_token（refresh_token 可能已过期）'), 'token' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'token' => $t];
    }

    /** 文件在 OneDrive 中的路径（以 / 开头） */
    private function itemPath(string $key): string
    {
        $d = trim($this->c('drive'), '/');
        return '/' . ($d !== '' ? $d . '/' : '') . $key;
    }

    private function graphBase(): string
    {
        $t = trim($this->c('tenant'));
        if ($t === '' || strpos($t, '.') === false) {
            $t = 'common';
        }
        return 'https://' . $t . '.graph.microsoft.com/v1.0';
    }

    public function urlPrefix(): string
    {
        // OneDrive 不做公开直链（文件默认私有），返回空表示不参与 URL 反解
        return '';
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $t = $this->accessToken();
        if (!$t['ok']) {
            return ['ok' => false, 'msg' => $t['msg'], 'url' => ''];
        }
        $url = $this->graphBase() . '/me/drive/root:' . $this->itemPath($key) . ':/content';
        $r = wm_storage_http('PUT', $url, [
            'Authorization: Bearer ' . $t['token'],
            'Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'),
            'Content-Length: ' . filesize($localFile),
        ], '', $localFile);
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => $r['msg'], 'url' => ''];
        }
        // 直接返回下载地址（有效期约 1 小时，页面刷新即重新获取）
        $j = json_decode((string)$r['body'], true);
        $url2 = is_array($j) && isset($j['@microsoft.graph.downloadUrl']) ? (string)$j['@microsoft.graph.downloadUrl'] : '';
        if ($url2 === '') {
            return ['ok' => false, 'msg' => '上传成功但未取得下载地址，OneDrive 文件默认私有，前台无法直接引用；请改用其他存储类型', 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $url2];
    }

    public function deleteByKey(string $key): bool
    {
        $t = $this->accessToken();
        if (!$t['ok']) {
            return false;
        }
        $r = wm_storage_http('DELETE', $this->graphBase() . '/me/drive/root:' . $this->itemPath($key), [
            'Authorization: Bearer ' . $t['token'],
        ]);
        return (bool)$r['ok'];
    }
}

// ============================================================================
// OpenList / Alist
// ============================================================================

class WmStorageOpenlist extends WmStorageBase
{
    private function base(): string
    {
        return rtrim($this->c('url'), '/') . '/api';
    }

    private function prefixPath(): string
    {
        $p = trim($this->c('prefix', '/pyq'));
        $p = trim($p, '/');
        return '/' . $p;
    }

    private function authToken(): array
    {
        $token = $this->c('token');
        if ($token !== '') {
            return ['ok' => true, 'msg' => 'ok', 'token' => $token];
        }
        $r = wm_storage_http('POST', $this->base() . '/auth/login', [
            'Content-Type: application/json',
        ], json_encode(['username' => $this->c('user'), 'password' => $this->c('pass')], JSON_UNESCAPED_UNICODE) ?: '{}');
        if (!$r['ok']) {
            return ['ok' => false, 'msg' => '登录 OpenList 失败：' . $r['msg'], 'token' => ''];
        }
        $j = json_decode((string)$r['body'], true);
        $t = is_array($j) && isset($j['data']['token']) ? (string)$j['data']['token'] : '';
        if ($t === '') {
            $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
            return ['ok' => false, 'msg' => '登录 OpenList 失败：' . ($msg !== '' ? $msg : '返回中无 token'), 'token' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'token' => $t];
    }

    public function urlPrefix(): string
    {
        // OpenList 的标准直链路由是 /d/<路径>
        $d = trim($this->c('url'), '/');
        $base = (preg_match('#^https?://#i', $d) ? $d : 'https://' . $d);
        return $base . '/d' . $this->prefixPath() . '/';
    }

    public function putObject(string $key, string $localFile, string $mime): array
    {
        $t = $this->authToken();
        if (!$t['ok']) {
            return ['ok' => false, 'msg' => $t['msg'], 'url' => ''];
        }
        $payload = json_encode([
            'path'     => $this->prefixPath() . '/' . $key,
            'url'      => $this->url($key),
            'headers'  => [],
            'asTask'   => false,
            'password' => '',
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $r = wm_storage_http('POST', $this->base() . '/fs/put', [
            'Authorization: ' . $t['token'],
            'Content-Type: application/json',
        ], $payload);
        if (!$r['ok']) {
            $j = json_decode((string)$r['body'], true);
            $msg = is_array($j) ? (string)($j['message'] ?? '') : '';
            return ['ok' => false, 'msg' => '上传失败：' . ($msg !== '' ? $msg : $r['msg']), 'url' => ''];
        }
        return ['ok' => true, 'msg' => 'ok', 'url' => $this->url($key)];
    }

    public function deleteByKey(string $key): bool
    {
        $t = $this->authToken();
        if (!$t['ok']) {
            return false;
        }
        $payload = json_encode([
            'dir'   => false,
            'dirs'  => [],
            'files' => [$this->prefixPath() . '/' . $key],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';
        $r = wm_storage_http('POST', $this->base() . '/fs/remove', [
            'Authorization: ' . $t['token'],
            'Content-Type: application/json',
        ], $payload);
        return (bool)$r['ok'];
    }
}

// ============================================================================
// 工厂与对外接口
// ============================================================================

/** 驱动类映射 */
function wm_storage_driver_map(): array
{
    return [
        'local'   => 'WmStorageLocal',
        'oss'     => 'WmStorageOss',
        'cos'     => 'WmStorageCos',
        'obs'     => 'WmStorageObs',
        'upyun'   => 'WmStorageUpyun',
        'qiniu'   => 'WmStorageQiniu',
        's3'      => 'WmStorageS3',
        'webdav'  => 'WmStorageWebdav',
        'onedrive'=> 'WmStorageOnedrive',
        'openlist'=> 'WmStorageOpenlist',
    ];
}

/** 当前存储驱动实例（本地存储返回 null） */
function wm_storage(): ?WmStorageBase
{
    static $instance = null;
    static $built = '';
    $type = wm_storage_type();
    if ($type === 'local') {
        return null;
    }
    $sig = $type . '|' . (string)wm_setting('storage_opts', '');
    if ($built === $sig && $instance instanceof WmStorageBase) {
        return $instance;
    }
    $map = wm_storage_driver_map();
    if (!isset($map[$type]) || !class_exists($map[$type])) {
        return null;
    }
    $instance = new $map[$type](wm_storage_cfg());
    $built = $sig;
    return $instance;
}

/** 取指定类型的驱动（后台「连接测试」用，可测未启用的类型） */
function wm_storage_make(string $type, array $cfg): ?WmStorageBase
{
    $map = wm_storage_driver_map();
    if (!isset($map[$type]) || !class_exists($map[$type])) {
        return null;
    }
    return new $map[$type]($cfg);
}

/** 解密后的当前配置（驱动内部使用，等价于 wm_storage_cfg()） */
function wm_storage_cfg_decoded(): array
{
    return wm_storage_cfg();
}

/**
 * 把本地成品文件发布到当前存储
 * @return array{ok:bool,path:string,warn:string} path 为写入媒体表的地址
 */
function wm_storage_publish(string $relPath, string $absPath, string $mime): array
{
    $drv = wm_storage();
    if ($drv === null) {
        return ['ok' => true, 'path' => $relPath, 'warn' => ''];
    }
    try {
        $r = $drv->put($relPath, $absPath, $mime);
    } catch (Throwable $e) {
        $r = ['ok' => false, 'msg' => $e->getMessage(), 'url' => ''];
    }
    if (!$r['ok'] || $r['url'] === '') {
        $msg = (string)($r['msg'] ?? '未知错误');
        error_log('storage put fail: ' . $relPath . ' → ' . $msg);
        // 回退保留本地文件：远端不通不该让站点彻底发不出图
        return ['ok' => true, 'path' => $relPath, 'warn' => '云存储上传失败（' . wm_cut($msg, 80) . '），已回退保存到本地'];
    }
    if (is_file($absPath)) {
        @unlink($absPath);
    }
    return ['ok' => true, 'path' => $r['url'], 'warn' => ''];
}

/**
 * 删除媒体文件（本地或远端）
 * @return bool true=已删除，或该文件本就不在本地（远端遗留由日志记录）；
 *              false=当前存储无法处理（例如存储类型是 local 而传入的是远端地址）
 */
function wm_storage_delete(string $path): bool
{
    $drv = wm_storage();
    if ($drv === null) {
        return false;
    }
    $path = trim($path);
    if ($path === '') {
        return false;
    }
    try {
        if (preg_match('#^https?://#i', $path)) {
            $key = $drv->keyFromUrl($path);
            if ($key === '') {
                // OneDrive 等私有类型无法由 URL 反解对象键：本地无需删，留日志便于排查
                error_log('storage delete skip: 无法由地址反解对象键 ' . wm_cut($path, 120));
                return true;
            }
            return $drv->deleteByKey($key);
        }
        // 存量数据是相对路径（云端对象键 = 前缀 + 相对路径）
        if (strpos($path, '..') !== false) {
            return false;
        }
        return $drv->deleteByKey($drv->key($path));
    } catch (Throwable $e) {
        error_log('storage delete fail: ' . $path . ' → ' . $e->getMessage());
        return false;
    }
}