-- ============================================================================
-- 朋友圈系统 升级脚本 (1.2.4 -> 1.3.3)
--
-- 适用对象：当前版本低于 1.3.0 的站点（1.2.4 是 1.3.0 之前的最后一个版本）。
--           1.2.4 已包含分享音乐(wm_music)等特性，无需再执行其它历史脚本。
--
-- 本文件包含
--   1) 新表 wm_user_oauth        第三方聚合登录（QQ / 微信快捷登录）绑定关系
--   2) 字段长度扩容             media.path / media.thumb / user.avatar / admin.avatar
--                               （云存储启用后地址为完整 URL，varchar(255) 不够用）
--   3) 新增设置项               oauth_*   聚合登录开关与凭据
--                               storage_* 存储类型与参数
--                               update_check_days / update_checked_at  自动检测新版本
--
-- 版本变化速览
--   1.3.0  新增首页相机入口、聚合登录、10 种存储类型
--   1.3.1  聚合登录改按服务商标准四步流程接入（act=login / act=callback）
--   1.3.2  修复 APPKEY 读出未解密导致 code=-1；新增用户自助绑定/解绑快捷登录
--   1.3.3  修复用户中心绑定行对齐；登录页换品牌图标；新增版本自动检测与邮件提醒
--
-- 执行方式（任选其一）
--   A. phpMyAdmin / Navicat 选中本站数据库，导入本文件
--   B. 命令行 mysql -u用户名 -p 数据库名 < upgrade.sql
--   C. 后台「在线更新」上传包含本文件的更新包，安装器更新后自动执行
--
-- 注意事项
--   * 表前缀默认 wm_，安装时若改过请全局替换后再执行
--   * 可重复执行：建表用 IF NOT EXISTS，设置项用 ON DUPLICATE KEY，
--     重复的字段长度变更会被在线更新自动忽略
--   * 未执行本文件时，聚合登录会自动降级为不可用并在后台提示，
--     站点其余功能不受影响
-- ============================================================================


-- ---------------------------------------------------------------------------
-- 1) 聚合登录绑定表
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wm_user_oauth` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `user_id` int unsigned NOT NULL DEFAULT '0',
    `provider` varchar(20) NOT NULL DEFAULT '' COMMENT 'qq/wechat',
    `openid` varchar(128) NOT NULL DEFAULT '' COMMENT '第三方用户标识',
    `nickname` varchar(50) NOT NULL DEFAULT '',
    `avatar` varchar(500) NOT NULL DEFAULT '',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_provider_openid` (`provider`,`openid`),
    KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='第三方聚合登录绑定';


-- ---------------------------------------------------------------------------
-- 2) 地址字段扩容（云存储启用后存完整 URL）
--    重复执行时若已是 varchar(500)，部分环境会报 1060/1061 类错误，
--    在线更新会把「已存在」类错误视为成功；手工导入出现该提示可忽略。
-- ---------------------------------------------------------------------------
ALTER TABLE `wm_media` MODIFY COLUMN `path` varchar(500) NOT NULL DEFAULT '';
ALTER TABLE `wm_media` MODIFY COLUMN `thumb` varchar(500) NOT NULL DEFAULT '';
ALTER TABLE `wm_user` MODIFY COLUMN `avatar` varchar(500) NOT NULL DEFAULT '';
ALTER TABLE `wm_admin` MODIFY COLUMN `avatar` varchar(500) NOT NULL DEFAULT '';


-- ---------------------------------------------------------------------------
-- 3) 新增设置项
-- ---------------------------------------------------------------------------
INSERT INTO `wm_setting` (`skey`, `svalue`) VALUES
  ('oauth_on', '0'),
  ('oauth_api', ''),
  ('oauth_appid', ''),
  ('oauth_appkey', ''),
  ('oauth_methods', 'qq,wechat'),
  ('oauth_auto_register', '1'),
  ('storage_type', 'local'),
  ('storage_opts', ''),
  ('update_check_days', '1'),
  ('update_checked_at', '0')
ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`);