-- =====================================================================
-- database/schema.sql
-- =====================================================================
-- Ejecuta este script en tu motor MySQL/MariaDB (XAMPP).
-- Crea la BD (si no existe) y las tablas del proyecto.
-- Charset y collation preparados para contenido multilenguaje (utf8mb4).

-- 1) Crea la base de datos si no existe (usa el nombre definido en .env)
CREATE DATABASE IF NOT EXISTS `jira_sync`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `jira_sync`;

-- 2) Tabla principal de incidencias sincronizadas desde Jira
--    - Guardamos tanto IDs como nombres para no depender sólo de los IDs.
--    - UNIQUE en jira_key para poder hacer UPSERT por clave legible.
CREATE TABLE IF NOT EXISTS `issues` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `jira_id`                BIGINT UNSIGNED NULL,   -- Jira issue numeric id (a veces grande)
  `jira_key`               VARCHAR(32) NOT NULL,   -- Clave legible (p.ej., LIP-123) - UNIQUE
  `summary`                TEXT NULL,              -- Título/resumen
  `status_name`            VARCHAR(64) NULL,
  `status_id`              VARCHAR(32) NULL,
  `priority_name`          VARCHAR(64) NULL,
  `priority_id`            VARCHAR(32) NULL,
  `assignee_account_id`    VARCHAR(128) NULL,
  `assignee_display_name`  VARCHAR(128) NULL,
  `project_key`            VARCHAR(32) NULL,
  `created_at`             DATETIME NULL,
  `updated_at`             DATETIME NULL,          -- updated (Jira)
  `last_synced_at`         DATETIME NULL,          -- cuándo sincronizamos este registro
  `estado_nivel`           TINYINT UNSIGNED NULL,  -- 1..5
  `prioridad_nivel`        TINYINT UNSIGNED NULL,  -- 1..5
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_issues_jira_key` (`jira_key`),
  KEY `idx_issues_updated_at` (`updated_at`),
  KEY `idx_issues_estado` (`estado_nivel`),
  KEY `idx_issues_prioridad` (`prioridad_nivel`),
  KEY `idx_issues_project` (`project_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Mapeo de estados de Jira -> nivel 1..5 (editable)
--    Puedes ajustar estos valores para tu flujo real.
CREATE TABLE IF NOT EXISTS `status_map` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `jira_status_name`  VARCHAR(64) NOT NULL,
  `estado_nivel`      TINYINT UNSIGNED NOT NULL,  -- 1..5
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_status_map_name` (`jira_status_name`),
  CONSTRAINT `chk_estado_nivel` CHECK (`estado_nivel` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semillas iniciales típicas (ajusta a tus nombres reales de workflow)
INSERT IGNORE INTO `status_map` (`jira_status_name`, `estado_nivel`) VALUES
  ('Nuevo',        1),
  ('To Do',        1),
  ('En progreso',  2),
  ('In Progress',  2),
  ('En revisión',  3),
  ('In Review',    3),
  ('En espera',    4),
  ('Waiting',      4),
  ('Hecho',        5),
  ('Done',         5);

-- 4) Mapeo de prioridades de Jira -> nivel 1..5 (editable)
CREATE TABLE IF NOT EXISTS `priority_map` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `jira_priority_name`  VARCHAR(64) NOT NULL,
  `prioridad_nivel`     TINYINT UNSIGNED NOT NULL, -- 1..5
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_priority_map_name` (`jira_priority_name`),
  CONSTRAINT `chk_prioridad_nivel` CHECK (`prioridad_nivel` BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Semillas típicas de prioridades por defecto de Jira
INSERT IGNORE INTO `priority_map` (`jira_priority_name`, `prioridad_nivel`) VALUES
  ('Lowest',   1),
  ('Low',      2),
  ('Medium',   3),
  ('High',     4),
  ('Highest',  5),
  ('Trivial',  1),
  ('Baja',     2),
  ('Media',    3),
  ('Alta',     4),
  ('Crítica',  5);

-- 5) Metadatos de sincronización (p.ej., last_sync_time)
--    name=value; un único registro 'issues_last_sync' con la última fecha ISO.
CREATE TABLE IF NOT EXISTS `sync_metadata` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(64) NOT NULL,
  `value`      VARCHAR(255) NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sync_metadata_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `sync_metadata` (`name`, `value`, `updated_at`)
VALUES ('issues_last_sync', NULL, NOW());
``