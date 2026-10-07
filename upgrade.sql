-- ============================================================================
-- 朋友圈系统 升级脚本 (1.1.2 -> 1.2.0)
--
-- 本次新增：分享音乐
--   1) 新表 wm_music           歌曲元信息，多条动态可复用同一条
--   2) wm_post 新增字段        music_id，0 表示该动态未分享音乐
--   3) 新增设置项              notify_on_update，检测到新版本时邮件通知站长
--
-- 执行方式（任选其一）
--   A. phpMyAdmin / Navicat 选中本站数据库，导入本文件
--   B. 命令行 mysql -u用户名 -p 数据库名 < upgrade.sql
--   C. 后台「在线更新」上传包含本文件的更新包，安装器自动执行
--
-- 注意事项
--   * 表前缀默认 wm_，安装时若改过请全局替换后再执行
--   * 可重复执行：建表用 IF NOT EXISTS；重复的字段/索引会被在线更新自动忽略
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1) 分享音乐表
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wm_music` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `platform` varchar(20) NOT NULL DEFAULT '' COMMENT 'netease/qq/kugou/kuwo/apple/spotify/link',
    `song_id` varchar(100) NOT NULL DEFAULT '' COMMENT '平台歌曲ID',
    `song_name` varchar(200) NOT NULL DEFAULT '',
    `artist` varchar(200) NOT NULL DEFAULT '',
    `album` varchar(200) NOT NULL DEFAULT '',
    `cover` varchar(500) NOT NULL DEFAULT '' COMMENT '封面地址',
    `url` varchar(500) NOT NULL DEFAULT '' COMMENT '歌曲页面地址',
    `audio` varchar(500) NOT NULL DEFAULT '' COMMENT '可直链播放地址，可能为空',
    `user_id` int unsigned NOT NULL DEFAULT '0',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_song` (`platform`,`song_id`),
    KEY `idx_user` (`user_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='分享音乐';


-- ---------------------------------------------------------------------------
-- 2) 动态表关联音乐
--    不使用 AFTER 指定列位置，避免旧库缺少该列时直接失败
-- ---------------------------------------------------------------------------
ALTER TABLE `wm_post` ADD COLUMN `music_id` int unsigned NOT NULL DEFAULT '0' COMMENT '分享音乐ID，0为无';

ALTER TABLE `wm_post` ADD KEY `idx_music` (`music_id`);


-- ---------------------------------------------------------------------------
-- 3) 新增设置项
-- ---------------------------------------------------------------------------
INSERT INTO `wm_setting` (`skey`, `svalue`) VALUES ('notify_on_update', '1') ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`);
