-- MySQL 8.0+，所有连接应使用 UTC。先创建数据库，再执行本文件。
CREATE TABLE ip_ipv4 (
  ip_bin BINARY(4) NOT NULL PRIMARY KEY,
  ip_text VARCHAR(15) NOT NULL,
  country_code VARCHAR(8) NULL,
  country VARCHAR(128) NULL,
  region_code VARCHAR(32) NULL,
  region VARCHAR(128) NULL,
  city VARCHAR(128) NULL,
  postal_code VARCHAR(32) NULL,
  continent_code VARCHAR(8) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  timezone VARCHAR(80) NULL,
  utc_offset INT NULL,
  asn BIGINT UNSIGNED NULL,
  asn_organization VARCHAR(255) NULL,
  isp VARCHAR(255) NULL,
  organization VARCHAR(255) NULL,
  raw_json JSON NOT NULL,
  source VARCHAR(32) NOT NULL DEFAULT 'ip.sb',
  fetch_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  fetched_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ip_text (ip_text),
  INDEX idx_expires (expires_at),
  INDEX idx_country_region_city (country_code, region(64), city(64)),
  INDEX idx_asn (asn),
  INDEX idx_isp (isp(96))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ip_ipv6 LIKE ip_ipv4;
ALTER TABLE ip_ipv6 MODIFY COLUMN ip_bin BINARY(16) NOT NULL;
ALTER TABLE ip_ipv6 MODIFY COLUMN ip_text VARCHAR(39) NOT NULL;

CREATE TABLE admin_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  scopes JSON NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  INDEX idx_status_expiry (is_enabled, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- 跨 Worker 共享滑动窗口。命名锁保护计数+插入；不存 Token 原文。
CREATE TABLE rate_limit_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  INDEX idx_bucket_time (bucket_hash, occurred_at),
  INDEX idx_time (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
