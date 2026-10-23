-- 仅适用于已按原开发文档建表、尚无 fetch_version 字段的实例。
-- 新安装 schema.sql 已含此字段，无需执行。先备份，停写后升级两个表。
ALTER TABLE ip_ipv4 ADD COLUMN fetch_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER source;
ALTER TABLE ip_ipv6 ADD COLUMN fetch_version BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER source;
