-- ============================================================
-- 朋友圈系统 - 旧版本升级脚本
-- ------------------------------------------------------------
-- 适用对象：在本项目改为「安装时一次性建全表结构」之前就已安装完成的站点。
-- 全新安装不需要执行本文件 —— 安装向导（install/sql.php）已建好完整结构。
--
-- 使用方法（三选一）：
--   1. 宝塔面板 / phpMyAdmin：导入本文件
--   2. 命令行：mysql -u 用户名 -p 数据库名 < upgrade.sql
--   3. 复制内容到 SQL 窗口手动逐条执行
--
-- 注意事项：
--   - 执行前请务必备份数据库
--   - 先把文件中所有的 `wm_` 替换成你安装时填写的「表前缀」
--   - 若某条提示 "Duplicate key name" / "column already exists"，
--     说明该项已存在，跳过该条继续即可，不影响其它语句
-- ============================================================


-- ---------- 1. 补齐 user_id 相关索引 ----------
-- 用途：用户中心「我的动态」、后台按用户筛选、删除用户等场景，
--       原先没有索引，数据量上来后会全表扫描。

ALTER TABLE `wm_post`    ADD INDEX `idx_user` (`user_id`, `id`);
ALTER TABLE `wm_comment` ADD INDEX `idx_user` (`user_id`, `id`);
ALTER TABLE `wm_like`    ADD INDEX `idx_user` (`user_id`);
ALTER TABLE `wm_media`   ADD INDEX `idx_user` (`user_id`, `id`);

-- 用途：后台「清理孤立文件」按 post_id + created_at 扫描未关联的媒体
ALTER TABLE `wm_media`   ADD INDEX `idx_orphan` (`post_id`, `created_at`);


-- ---------- 2. 点赞去重键升级 ----------
-- 旧结构 uk_post_ip(post_id, ip_hash) 只认 IP，会带来两个问题：
--   a) 同一出口 NAT（公司 / 校园 / 移动网络）下只有一个人能点赞；
--   b) 登录用户换 IP 后可以重复点赞。
-- 改为生成列 owner_key：user_id > 0 时取 'u'+账号，否则取 'i'+IP，
-- 再对 (post_id, owner_key) 建唯一键，实现「登录按账号、匿名按 IP」。
--
-- 需要 MySQL 5.7+ / MariaDB 5.2+。若数据库版本更低，请跳过本节，
-- 程序会保持旧的 IP 维度约束继续运行（功能降级，不会报错）。

ALTER TABLE `wm_like` ADD COLUMN `owner_key` varchar(64)
    GENERATED ALWAYS AS (IF(user_id > 0, CONCAT('u', user_id), CONCAT('i', ip_hash))) VIRTUAL;

ALTER TABLE `wm_like` ADD UNIQUE KEY `uk_owner` (`post_id`, `owner_key`);

-- 确认上面一条执行成功后，再执行这一条删除旧的 IP 维度唯一键。
-- 如果 ADD UNIQUE KEY 因历史数据重复而失败，请勿执行本行。
ALTER TABLE `wm_like` DROP INDEX `uk_post_ip`;


-- ---------- 3. 清理失效的迁移标记（可选）----------
-- 旧版本会在设置表写入 schema_version 用于判断是否执行迁移，
-- 新版本已移除运行时迁移逻辑，该项不再被读取。

DELETE FROM `wm_setting` WHERE `skey` = 'schema_version';
