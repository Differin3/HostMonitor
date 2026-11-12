-- Fail2ban: состояние сбора с нод (Фаза 1 — только чтение).
--
-- Агент раз в несколько циклов присылает снимок: версию, список джейлов,
-- эффективные параметры и активные баны. История банов строится на панели
-- диффом между снимками (fail2ban_events), потому что sqlite fail2ban
-- хранит её только сутки (dbpurgeage=1d).

-- По одной строке на ноду: установлен ли fail2ban, работает ли служба.
CREATE TABLE IF NOT EXISTS fail2ban_status (
  node_id INT NOT NULL PRIMARY KEY,
  installed TINYINT(1) NOT NULL DEFAULT 0,
  version VARCHAR(32) NULL,
  active TINYINT(1) NOT NULL DEFAULT 0,
  jail_count INT NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  collected_at DATETIME NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Состояние каждого джейла: счётчики фильтра и действий, параметры.
CREATE TABLE IF NOT EXISTS fail2ban_jails (
  id INT AUTO_INCREMENT PRIMARY KEY,
  node_id INT NOT NULL,
  jail VARCHAR(64) NOT NULL,
  currently_failed INT NOT NULL DEFAULT 0,
  total_failed INT NOT NULL DEFAULT 0,
  currently_banned INT NOT NULL DEFAULT 0,
  total_banned INT NOT NULL DEFAULT 0,
  logpath VARCHAR(512) NULL,
  backend VARCHAR(32) NULL,
  maxretry INT NULL,
  findtime INT NULL,
  bantime INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_node_jail (node_id, jail),
  KEY idx_node (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Баны: активные и истёкшие. UNIQUE (node_id, jail, ip) — ключ для диффа.
CREATE TABLE IF NOT EXISTS fail2ban_bans (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  node_id INT NOT NULL,
  jail VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  banned_at DATETIME NULL,
  expires_at DATETIME NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  UNIQUE KEY uq_node_jail_ip (node_id, jail, ip),
  KEY idx_node_active (node_id, active),
  KEY idx_ip (ip),
  KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- События ban/unban/discovered — источник графика и истории.
CREATE TABLE IF NOT EXISTS fail2ban_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  node_id INT NOT NULL,
  jail VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  event ENUM('ban','unban','discovered') NOT NULL,
  ts DATETIME NOT NULL,
  KEY idx_node_ts (node_id, ts),
  KEY idx_ts (ts),
  KEY idx_jail (jail)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Новый scope для нод с Ed25519: приём снимков fail2ban.
-- legacy:full и NULL-scopes не трогаем: legacy-ноды и так проходят любую проверку,
-- а NULL означает старый агент без назначенных прав.
UPDATE nodes
   SET scopes = JSON_ARRAY_APPEND(scopes, '$', 'fail2ban:write')
 WHERE scopes IS NOT NULL
   AND JSON_VALID(scopes)
   AND NOT JSON_CONTAINS(scopes, '"fail2ban:write"')
   AND NOT JSON_CONTAINS(scopes, '"legacy:full"');
