<?php
declare(strict_types=1);
if (!defined('WM_INIT')) { exit('403'); }

function wm_migrate(): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $db = wm_db();
        $p = DB_PREFIX;
        $db->exec("CREATE TABLE IF NOT EXISTS `{$p}user` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `username` varchar(30) NOT NULL,
            `password` varchar(255) NOT NULL,
            `nickname` varchar(50) NOT NULL DEFAULT '',
            `email` varchar(120) NOT NULL DEFAULT '',
            `avatar` varchar(255) NOT NULL DEFAULT '',
            `signature` varchar(255) NOT NULL DEFAULT '',
            `status` tinyint NOT NULL DEFAULT 1,
            `pass_version` int unsigned NOT NULL DEFAULT 1,
            `last_login_at` datetime DEFAULT NULL,
            `last_login_ip` varchar(45) NOT NULL DEFAULT '',
            `login_count` int unsigned NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uk_username` (`username`), KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $add = static function (string $table, string $column, string $definition) use ($db, $p): void {
            $st = $db->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $st->execute([DB_NAME, $p . $table, $column]);
            if ((int)$st->fetchColumn() === 0) {
                $db->exec("ALTER TABLE `{$p}{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        };
        $add('post', 'user_id', 'int unsigned NOT NULL DEFAULT 0 AFTER admin_id');
        $add('comment', 'user_id', 'int unsigned NOT NULL DEFAULT 0 AFTER post_id');
        $add('like', 'user_id', 'int unsigned NOT NULL DEFAULT 0 AFTER post_id');
        $add('media', 'user_id', 'int unsigned NOT NULL DEFAULT 0 AFTER post_id');
        $add('user', 'blocked_at', 'datetime DEFAULT NULL AFTER status');
    } catch (Throwable $e) {
        error_log('migration fail: ' . $e->getMessage());
    }
}
