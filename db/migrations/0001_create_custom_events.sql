-- Migration: 0001_create_custom_events.sql
-- Creates bot_custom_events and bot_custom_event_builders tables.
-- Safe for both fresh installs (CREATE IF NOT EXISTS) and existing
-- installations that are missing the group_name column (ADD COLUMN IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `bot_custom_events` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bot_id`              BIGINT UNSIGNED NOT NULL,
    `name`                VARCHAR(120)    NOT NULL DEFAULT '',
    `event_type`          VARCHAR(80)     NOT NULL DEFAULT '',
    `description`         VARCHAR(255)    NOT NULL DEFAULT '',
    `is_enabled`          TINYINT(1)      NOT NULL DEFAULT 1,
    `group_name`          VARCHAR(80)     NULL     DEFAULT NULL,
    `created_by_user_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_by_user_id`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ce_bot`         (`bot_id`),
    KEY `idx_ce_bot_group`   (`bot_id`, `group_name`),
    KEY `idx_ce_bot_enabled` (`bot_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_custom_event_builders` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `custom_event_id`  BIGINT UNSIGNED NOT NULL,
    `builder_json`     LONGTEXT        NOT NULL,
    `builder_version`  INT UNSIGNED    NOT NULL DEFAULT 1,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ceb_event` (`custom_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: for existing installations that have bot_custom_events without the
-- group_name column, bh_ce_ensure_tables() handles the ALTER TABLE automatically
-- via an information_schema check (MySQL 5.7 compatible).
