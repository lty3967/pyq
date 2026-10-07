<?php
/**
 * 轻量 SMTP 发信实现（无第三方依赖），支持 SSL/TLS 与 AUTH LOGIN/PLAIN
 */
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

class WmMailer
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $secure;   // '', 'ssl', 'tls'
    private string $fromMail;
    private string $fromName;
    private int $timeout = 15;
    /** @var resource|null */
    private $sock = null;
    private array $trace = [];
    /** 认证阶段标志：该阶段的命令一律不写入 trace，避免凭据泄露 */
    private bool $authPhase = false;

    public function __construct(array $cfg = [])
    {
        $this->host     = (string)($cfg['host'] ?? wm_setting('smtp_host', ''));
        $this->port     = (int)($cfg['port'] ?? wm_setting('smtp_port', '465'));
        $this->user     = (string)($cfg['user'] ?? wm_setting('smtp_user', ''));
        $this->pass     = wm_secret_decode((string)($cfg['pass'] ?? wm_setting('smtp_pass', '')));
        $this->secure   = (string)($cfg['secure'] ?? wm_setting('smtp_secure', 'ssl'));
        $this->fromMail = (string)($cfg['from'] ?? wm_setting('smtp_from', $this->user));
        $this->fromName = (string)($cfg['from_name'] ?? wm_setting('smtp_from_name', wm_setting('site_name', '朋友圈')));
    }

    public function trace(): array
    {
        return $this->trace;
    }

    public function configured(): bool
    {
        return $this->host !== '' && $this->port > 0 && $this->fromMail !== '';
    }

    /**
     * 发送邮件
     * @param string|array $to
     */
    public function send($to, string $subject, string $htmlBody, string $textBody = ''): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'msg' => 'SMTP 未配置完整'];
        }
        $tos = is_array($to) ? $to : [$to];
        $rcpt = [];
        foreach ($tos as $t) {
            $t = trim((string)$t);
            if ($t !== '' && filter_var($t, FILTER_VALIDATE_EMAIL)) {
                $rcpt[] = $t;
            }
        }
        if (!$rcpt) {
            return ['ok' => false, 'msg' => '收件人地址无效'];
        }
        if (!filter_var($this->fromMail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '发件人地址无效'];
        }
        // 防头注入
        $subject = preg_replace('/[\r\n]+/', ' ', $subject) ?? '';

        try {
            $this->connect();
            $this->ehlo();
            if ($this->secure === 'tls') {
                $this->cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS 协商失败');
                }
                $this->ehlo();
            }
            if ($this->user !== '' && $this->pass !== '') {
                $this->auth();
            }
            $this->cmd('MAIL FROM:<' . $this->fromMail . '>', [250]);
            foreach ($rcpt as $r) {
                $this->cmd('RCPT TO:<' . $r . '>', [250, 251]);
            }
            $this->cmd('DATA', [354]);
            $this->write($this->buildMessage($rcpt, $subject, $htmlBody, $textBody) . "\r\n.\r\n");
            $this->expect([250]);
            $this->cmd('QUIT', [221, 250], true);
            $this->close();
            return ['ok' => true, 'msg' => '发送成功'];
        } catch (Throwable $e) {
            $this->close();
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
    }

    private function connect(): void
    {
        $host = $this->host;
        if ($this->secure === 'ssl') {
            $host = 'ssl://' . $host;
        }
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ],
        ]);
        $errno = 0; $errstr = '';
        $sock = @stream_socket_client($host . ':' . $this->port, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock === false) {
            throw new RuntimeException('连接 SMTP 服务器失败：' . ($errstr !== '' ? $errstr : ('错误码 ' . $errno)));
        }
        stream_set_timeout($sock, $this->timeout);
        $this->sock = $sock;
        $this->expect([220]);
    }

    private function ehlo(): void
    {
        $domain = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $domain = preg_replace('/[^A-Za-z0-9\.\-]/', '', explode(':', (string)$domain)[0]) ?: 'localhost';
        try {
            $this->cmd('EHLO ' . $domain, [250]);
        } catch (Throwable $e) {
            $this->cmd('HELO ' . $domain, [250]);
        }
    }

    private function auth(): void
    {
        $this->authPhase = true;
        try {
            $this->cmd('AUTH LOGIN', [334]);
            $this->cmd(base64_encode($this->user), [334]);
            $this->cmd(base64_encode($this->pass), [235]);
        } catch (Throwable $e) {
            $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $this->user . "\0" . $this->pass), [235]);
        } finally {
            $this->authPhase = false;
        }
    }

    private function buildMessage(array $rcpt, string $subject, string $html, string $text): string
    {
        $boundary = 'wm_' . bin2hex(random_bytes(12));
        $encName = '=?UTF-8?B?' . base64_encode($this->fromName) . '?=';
        if ($text === '') {
            $text = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html), ENT_QUOTES, 'UTF-8'));
        }
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $encName . ' <' . $this->fromMail . '>',
            'To: ' . implode(', ', $rcpt),
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (explode(':', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))[0]) . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: WmMailer',
        ];
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . '--' . $boundary . "--\r\n";
        // 点开头行转义
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function cmd(string $cmd, array $expect, bool $ignoreFail = false): string
    {
        $this->write($cmd . "\r\n");
        // 认证阶段或形如 base64 的命令一律脱敏，短账号/短密码同样要隐藏
        $safe = ($this->authPhase || preg_match('/^(AUTH|[A-Za-z0-9+\/=]{6,})/', $cmd)) ? '[hidden]' : $cmd;
        $this->trace[] = '> ' . $safe;
        try {
            return $this->expect($expect);
        } catch (Throwable $e) {
            if ($ignoreFail) { return ''; }
            throw $e;
        }
    }

    private function write(string $data): void
    {
        if ($this->sock === null) { throw new RuntimeException('连接已关闭'); }
        if (@fwrite($this->sock, $data) === false) {
            throw new RuntimeException('写入 SMTP 失败');
        }
    }

    private function expect(array $codes): string
    {
        if ($this->sock === null) { throw new RuntimeException('连接已关闭'); }
        $resp = '';
        while (($line = fgets($this->sock, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4) { break; }
            if ($line[3] !== '-') { break; }
        }
        $meta = stream_get_meta_data($this->sock);
        if (!empty($meta['timed_out'])) {
            throw new RuntimeException('SMTP 响应超时');
        }
        $code = (int)substr(trim($resp), 0, 3);
        $this->trace[] = '< ' . trim(mb_substr($resp, 0, 200));
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP 错误响应：' . trim(mb_substr($resp, 0, 200)));
        }
        return $resp;
    }

    private function close(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }
}

/** 简单邮件模板 */
function wm_mail_template(string $title, string $content): string
{
    $site = e((string)wm_setting('site_name', '朋友圈'));
    return '<div style="background:#f5f5f5;padding:24px 0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06)">'
        . '<div style="background:#07c160;color:#fff;padding:18px 24px;font-size:17px;font-weight:600">' . e($title) . '</div>'
        . '<div style="padding:24px;color:#333;font-size:15px;line-height:1.8">' . $content . '</div>'
        . '<div style="padding:16px 24px;background:#fafafa;color:#999;font-size:12px">本邮件由 ' . $site . ' 系统自动发送</div>'
        . '</div></div>';
}

/**
 * 检测到新版本时邮件通知站长
 *
 * 去重规则：记录「已通知过的版本号」，同一版本只发一次。
 * update.php 每次打开页面都会自动检测一次，没有去重的话站长会天天收到重复邮件。
 *
 * @return array{ok:bool,msg:string} ok=false 时 msg 说明未发送的原因
 */
function wm_notify_update(string $latest, string $current, string $time = '', string $changelog = '', int $adminId = 0): array
{
    if ((string)wm_setting('notify_on_update', '1') !== '1') {
        return ['ok' => false, 'msg' => '已关闭新版本邮件通知'];
    }
    if ($latest === '' || version_compare($latest, $current, '<=')) {
        return ['ok' => false, 'msg' => '没有新版本'];
    }
    if ((string)wm_setting('update_notified_version', '') === $latest) {
        return ['ok' => false, 'msg' => '该版本已通知过'];
    }

    // 收件人：优先「发信功能」里配置的站长邮箱，其次当前管理员账号邮箱
    $to = trim((string)wm_setting('notify_email', ''));
    if ($to === '' && $adminId > 0) {
        $row = wm_one('SELECT email FROM ' . wm_t('admin') . ' WHERE id = ? LIMIT 1', [$adminId]);
        $to = $row !== null ? trim((string)($row['email'] ?? '')) : '';
    }
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => '未配置有效的站长邮箱'];
    }

    // 双保险：限流之外再做一层按版本的去重间隔
    if (!wm_rate_limit('update_notify', 6, 3600)) {
        return ['ok' => false, 'msg' => '通知过于频繁，已跳过'];
    }

    $siteName = (string)wm_setting('site_name', '朋友圈');
    $detail = '<p>站点 <b>' . e($siteName) . '</b> 检测到新版本：<b style="color:#07c160">V' . e($latest) . '</b></p>'
        . '<p>当前版本：V' . e($current) . ($time !== '' ? '，发布时间：' . e($time) : '') . '</p>';
    if ($changelog !== '') {
        $detail .= '<p style="color:#666">更新内容：</p>'
            . '<pre style="white-space:pre-wrap;background:#fafafa;border:1px solid #eee;border-radius:8px;padding:12px;'
            . 'margin:10px 0;font:13px/1.7 monospace;color:#333">' . e(wm_cut($changelog, 3000)) . '</pre>';
    }
    $detail .= '<p style="color:#999;font-size:13px">请登录后台「在线更新」页面执行升级。</p>';

    $subject = '【' . $siteName . '】检测到新版本 V' . $latest;
    $body = wm_mail_template('发现新版本 V' . $latest, $detail);

    try {
        $mailer = new WmMailer();
        $res = $mailer->send($to, $subject, $body);
        wm_exec('INSERT INTO ' . wm_t('mail') . ' (to_mail, subject, body, status, result, admin_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [mb_substr($to, 0, 480), mb_substr($subject, 0, 200), mb_substr($body, 0, 60000),
             $res['ok'] ? 1 : 2, mb_substr((string)$res['msg'], 0, 400), $adminId]);
        if ($res['ok']) {
            // 只有真发出去了才记版本号，失败时下次检测还能重试
            wm_setting_set('update_notified_version', $latest);
            wm_log('新版本邮件通知', 'V' . $latest . ' → ' . $to, $adminId);
            return ['ok' => true, 'msg' => '已通知 ' . $to];
        }
        error_log('update notify mail fail: ' . (string)$res['msg']);
        return ['ok' => false, 'msg' => '发送失败：' . $res['msg']];
    } catch (Throwable $e) {
        // 通知失败绝不能影响「检查更新」这个只读接口的响应
        error_log('update notify fail: ' . $e->getMessage());
        return ['ok' => false, 'msg' => '通知异常'];
    }
}
