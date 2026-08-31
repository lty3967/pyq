<?php
/**
 * 数据表结构定义（安装时执行）
 */
declare(strict_types=1);
if (!defined('WM_INSTALL')) { exit('403'); }

/**
 * @return string[] SQL 语句数组
 */
function wm_schema(string $p): array
{
    return [
        // 普通用户
        "CREATE TABLE IF NOT EXISTS `{$p}user` (
            `id` int unsigned NOT NULL AUTO_INCREMENT, `username` varchar(30) NOT NULL,
            `password` varchar(255) NOT NULL, `nickname` varchar(50) NOT NULL DEFAULT '',
            `email` varchar(120) NOT NULL DEFAULT '', `avatar` varchar(255) NOT NULL DEFAULT '',
            `signature` varchar(255) NOT NULL DEFAULT '', `status` tinyint NOT NULL DEFAULT 1,
            `blocked_at` datetime DEFAULT NULL, `pass_version` int unsigned NOT NULL DEFAULT 1,
            `last_login_at` datetime DEFAULT NULL, `last_login_ip` varchar(45) NOT NULL DEFAULT '',
            `login_count` int unsigned NOT NULL DEFAULT 0, `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`), UNIQUE KEY `uk_username` (`username`), KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        // 管理员
        "CREATE TABLE IF NOT EXISTS `{$p}admin` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `password` varchar(255) NOT NULL,
            `nickname` varchar(50) NOT NULL DEFAULT '',
            `email` varchar(120) NOT NULL DEFAULT '',
            `avatar` varchar(255) NOT NULL DEFAULT '',
            `signature` varchar(255) NOT NULL DEFAULT '',
            `role` varchar(20) NOT NULL DEFAULT 'admin',
            `status` tinyint NOT NULL DEFAULT '1',
            `pass_version` int unsigned NOT NULL DEFAULT '1',
            `last_login_at` datetime DEFAULT NULL,
            `last_login_ip` varchar(45) NOT NULL DEFAULT '',
            `login_count` int unsigned NOT NULL DEFAULT '0',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 分类
        "CREATE TABLE IF NOT EXISTS `{$p}category` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(50) NOT NULL,
            `slug` varchar(60) NOT NULL DEFAULT '',
            `color` varchar(20) NOT NULL DEFAULT '#07c160',
            `sort` int NOT NULL DEFAULT '0',
            `status` tinyint NOT NULL DEFAULT '1',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_name` (`name`),
            KEY `idx_sort` (`sort`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 朋友圈动态
        "CREATE TABLE IF NOT EXISTS `{$p}post` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `admin_id` int unsigned NOT NULL DEFAULT '0',
            `user_id` int unsigned NOT NULL DEFAULT '0',
            `cat_id` int unsigned NOT NULL DEFAULT '0',
            `content` text NOT NULL,
            `media_type` varchar(10) NOT NULL DEFAULT 'none',
            `location` varchar(100) NOT NULL DEFAULT '',
            `views` int unsigned NOT NULL DEFAULT '0',
            `likes` int unsigned NOT NULL DEFAULT '0',
            `comments` int unsigned NOT NULL DEFAULT '0',
            `status` tinyint NOT NULL DEFAULT '1',
            `is_top` tinyint NOT NULL DEFAULT '0',
            `allow_comment` tinyint NOT NULL DEFAULT '1',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_list` (`status`,`is_top`,`id`),
            KEY `idx_cat` (`cat_id`,`status`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 媒体
        "CREATE TABLE IF NOT EXISTS `{$p}media` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `post_id` int unsigned NOT NULL DEFAULT '0',
            `user_id` int unsigned NOT NULL DEFAULT '0',
            `type` varchar(10) NOT NULL DEFAULT 'image',
            `path` varchar(255) NOT NULL,
            `thumb` varchar(255) NOT NULL DEFAULT '',
            `width` int unsigned NOT NULL DEFAULT '0',
            `height` int unsigned NOT NULL DEFAULT '0',
            `size` bigint unsigned NOT NULL DEFAULT '0',
            `sort` int NOT NULL DEFAULT '0',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_post` (`post_id`,`sort`),
            KEY `idx_type` (`type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 评论
        "CREATE TABLE IF NOT EXISTS `{$p}comment` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `post_id` int unsigned NOT NULL,
            `user_id` int unsigned NOT NULL DEFAULT '0',
            `parent_id` int unsigned NOT NULL DEFAULT '0',
            `nickname` varchar(50) NOT NULL,
            `email` varchar(120) NOT NULL DEFAULT '',
            `content` varchar(1000) NOT NULL,
            `status` tinyint NOT NULL DEFAULT '0' COMMENT '0待审 1通过 2屏蔽',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `ip_hash` varchar(32) NOT NULL DEFAULT '',
            `ua` varchar(255) NOT NULL DEFAULT '',
            `bad_hit` varchar(255) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_post` (`post_id`,`status`,`id`),
            KEY `idx_status` (`status`,`id`),
            KEY `idx_parent` (`parent_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 点赞
        "CREATE TABLE IF NOT EXISTS `{$p}like` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `post_id` int unsigned NOT NULL,
            `user_id` int unsigned NOT NULL DEFAULT '0',
            `ip_hash` varchar(32) NOT NULL,
            `nickname` varchar(50) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_post_ip` (`post_id`,`ip_hash`),
            KEY `idx_post` (`post_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 浏览记录（按 IP 去重当天）
        "CREATE TABLE IF NOT EXISTS `{$p}view` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `post_id` int unsigned NOT NULL,
            `ip_hash` varchar(32) NOT NULL,
            `day` date NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_view` (`post_id`,`ip_hash`,`day`),
            KEY `idx_day` (`day`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 设置
        "CREATE TABLE IF NOT EXISTS `{$p}setting` (
            `skey` varchar(60) NOT NULL,
            `svalue` text NOT NULL,
            PRIMARY KEY (`skey`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 频率限制
        "CREATE TABLE IF NOT EXISTS `{$p}ratelimit` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `rkey` varchar(64) NOT NULL,
            `hits` int unsigned NOT NULL DEFAULT '0',
            `expire_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_rkey` (`rkey`),
            KEY `idx_expire` (`expire_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 操作日志
        "CREATE TABLE IF NOT EXISTS `{$p}log` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `admin_id` int unsigned NOT NULL DEFAULT '0',
            `action` varchar(50) NOT NULL DEFAULT '',
            `detail` varchar(500) NOT NULL DEFAULT '',
            `ip` varchar(45) NOT NULL DEFAULT '',
            `ua` varchar(255) NOT NULL DEFAULT '',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_admin` (`admin_id`,`id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // 发信记录
        "CREATE TABLE IF NOT EXISTS `{$p}mail` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `to_mail` varchar(500) NOT NULL,
            `subject` varchar(200) NOT NULL DEFAULT '',
            `body` text NOT NULL,
            `status` tinyint NOT NULL DEFAULT '0',
            `result` varchar(500) NOT NULL DEFAULT '',
            `admin_id` int unsigned NOT NULL DEFAULT '0',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** 默认设置项 */
function wm_default_settings(): array
{
    return [
        'site_name'          => '我的朋友圈',
        'site_desc'          => '记录生活点滴',
        'owner_name'         => '站长',
        'owner_avatar'       => '',
        'owner_signature'    => '这个人很懒，什么都没写',
        'cover_image'        => '',
        'page_size'          => '10',
        'comment_need_audit' => '1',
        'comment_interval'   => '30',
        'comment_max_per_hour' => '10',
        'comment_mask_mode'  => 'reject',
        'bad_words'          => "违禁词示例\n赌博\n色情\n代开发票",
        'allow_like'         => '1',
        'allow_comment'      => '1',
        'max_image_mb'       => '10',
        'max_video_mb'       => '40',
        'image_max_side'     => '2000',
        'image_quality'      => '86',
        'thumb_size'         => '400',
        'login_max_fail'     => '5',
        'login_lock_seconds' => '900',
        'session_timeout'    => '7200',
        'trust_proxy'        => '1',
        'smtp_host'          => '',
        'smtp_port'          => '465',
        'smtp_user'          => '',
        'smtp_pass'          => '',
        'smtp_secure'        => 'ssl',
        'smtp_from'          => '',
        'smtp_from_name'     => '我的朋友圈',
        'notify_email'       => '',
        'notify_on_comment'  => '0',
    ];
}
