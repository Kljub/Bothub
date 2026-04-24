SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- =========================================================
-- SCHEMA MIGRATIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `filename`   VARCHAR(190)    NOT NULL,
  `checksum`   CHAR(64)        NOT NULL,
  `applied_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schema_migrations_filename` (`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ADMIN SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `admin_settings` (
  `setting_key`   VARCHAR(64) NOT NULL,
  `setting_value` TEXT        NULL,
  `updated_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- USERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(100)    NOT NULL,
  `email`         VARCHAR(190)    NOT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `role`          ENUM('admin','user') NOT NULL DEFAULT 'user',
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email`    (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- USER SESSIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       BIGINT UNSIGNED NOT NULL,
  `session_token` CHAR(64)        NOT NULL,
  `ip_address`    VARCHAR(45)     NULL,
  `user_agent`    VARCHAR(255)    NULL,
  `expires_at`    DATETIME        NOT NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_sessions_token`  (`session_token`),
  KEY `idx_user_sessions_user`         (`user_id`),
  KEY `idx_user_sessions_expires`      (`expires_at`),
  CONSTRAINT `fk_user_sessions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- USER PLEX ACCOUNTS
-- =========================================================
CREATE TABLE IF NOT EXISTS `user_plex_accounts` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           BIGINT UNSIGNED NOT NULL,
  `plex_token_enc`    TEXT            NULL,
  `client_identifier` VARCHAR(128)    NOT NULL,
  `status`            ENUM('connected','disconnected') NOT NULL DEFAULT 'disconnected',
  `connected_at`      DATETIME        NULL,
  `last_sync_at`      DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_plex_accounts_user_id`   (`user_id`),
  KEY `idx_user_plex_accounts_status`          (`status`),
  KEY `idx_user_plex_accounts_last_sync_at`    (`last_sync_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- USER PLEX SERVERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `user_plex_servers` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `plex_account_id`     BIGINT UNSIGNED NOT NULL,
  `resource_identifier` VARCHAR(191)    NOT NULL,
  `name`                VARCHAR(191)    NOT NULL,
  `product`             VARCHAR(100)    NOT NULL DEFAULT '',
  `product_version`     VARCHAR(100)    NOT NULL DEFAULT '',
  `platform`            VARCHAR(100)    NOT NULL DEFAULT '',
  `platform_version`    VARCHAR(100)    NOT NULL DEFAULT '',
  `device`              VARCHAR(100)    NOT NULL DEFAULT '',
  `client_identifier`   VARCHAR(191)    NOT NULL DEFAULT '',
  `owned`               TINYINT(1)      NOT NULL DEFAULT 0,
  `presence`            TINYINT(1)      NOT NULL DEFAULT 0,
  `access_token`        TEXT            NULL,
  `connections_json`    LONGTEXT        NULL,
  `raw_json`            LONGTEXT        NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_plex_servers_resource` (`plex_account_id`, `resource_identifier`),
  KEY `idx_user_plex_servers_presence`       (`presence`),
  KEY `idx_user_plex_servers_name`           (`name`),
  CONSTRAINT `fk_user_plex_servers_account`
    FOREIGN KEY (`plex_account_id`) REFERENCES `user_plex_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- RUNNERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `runners` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(100)    NOT NULL,
  `public_key` CHAR(64)        NOT NULL,
  `ip_address` VARCHAR(45)     NULL,
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
  `last_seen`  DATETIME        NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_runners_public_key` (`public_key`),
  KEY `idx_runners_last_seen`        (`last_seen`),
  KEY `idx_runners_active`           (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- CORE RUNNERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `core_runners` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `runner_name` VARCHAR(100)    NOT NULL,
  `endpoint`    VARCHAR(255)    NOT NULL,
  `status`      ENUM('online','offline','unknown') DEFAULT 'unknown',
  `last_ping`   DATETIME        NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME        NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT INSTANCES
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_instances` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_user_id` BIGINT UNSIGNED NOT NULL,
  `display_name`  VARCHAR(150)    NOT NULL,
  `discord_app_id`       BIGINT UNSIGNED NULL,
  `discord_bot_user_id`  BIGINT UNSIGNED NULL,
  `bot_token_encrypted`  MEDIUMTEXT      NULL,
  `bot_token_enc_meta`   JSON            NULL,
  `desired_state`  ENUM('stopped','running')                          NOT NULL DEFAULT 'stopped',
  `runtime_status` ENUM('stopped','running','error','unknown')        NOT NULL DEFAULT 'unknown',
  `last_error`     TEXT     NULL,
  `last_started_at`  DATETIME NULL,
  `last_stopped_at`  DATETIME NULL,
  `username_change_blocked_until` DATETIME NULL,
  `avatar_change_blocked_until`   DATETIME NULL,
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
  `assigned_runner_name` VARCHAR(100) NULL DEFAULT NULL,
  `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bots_owner`            (`owner_user_id`),
  KEY `idx_bots_desired_state`    (`desired_state`),
  KEY `idx_bots_runtime_status`    (`runtime_status`),
  KEY `idx_bots_runner`             (`assigned_runner_name`),
  UNIQUE KEY `uq_bots_discord_app_id` (`discord_app_id`),
  CONSTRAINT `fk_bot_instances_owner`
    FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_settings` (
  `bot_id`     BIGINT UNSIGNED NOT NULL,
  `config_json` JSON           NOT NULL,
  `updated_at`  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bot_id`),
  CONSTRAINT `fk_bot_settings_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT SECRETS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_secrets` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`          BIGINT UNSIGNED NOT NULL,
  `key_name`        VARCHAR(100)    NOT NULL DEFAULT 'bot_secret',
  `value_encrypted` MEDIUMTEXT      NOT NULL,
  `enc_meta_json`   JSON            NULL,
  `version`         INT UNSIGNED    NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bot_secrets_bot_key`               (`bot_id`, `key_name`),
  UNIQUE KEY `uq_bot_secrets_bot_key_version` (`bot_id`, `key_name`, `version`),
  CONSTRAINT `fk_bot_secrets_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT LEASES
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_leases` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `runner_id`    BIGINT UNSIGNED NOT NULL,
  `lease_token`  CHAR(64)        NOT NULL,
  `leased_until` DATETIME        NOT NULL,
  `heartbeat_at` DATETIME        NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_leases_bot`   (`bot_id`),
  UNIQUE KEY `uq_bot_leases_token` (`lease_token`),
  KEY `idx_bot_leases_runner_until` (`runner_id`, `leased_until`),
  CONSTRAINT `fk_bot_leases_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bot_leases_runner`
    FOREIGN KEY (`runner_id`) REFERENCES `runners` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- RUNNER JOBS
-- =========================================================
CREATE TABLE IF NOT EXISTS `runner_jobs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_uid`          CHAR(36)        NOT NULL,
  `bot_id`           BIGINT UNSIGNED NULL,
  `target_runner_id` BIGINT UNSIGNED NULL,
  `job_type` ENUM('bot_start','bot_stop','bot_restart','bot_deploy','bot_sync_config','runner_ping') NOT NULL,
  `payload_json` JSON NULL,
  `status`   ENUM('queued','leased','running','done','failed','canceled') NOT NULL DEFAULT 'queued',
  `priority` INT  NOT NULL DEFAULT 0,
  `attempts`     INT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` INT UNSIGNED NOT NULL DEFAULT 5,
  `available_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `leased_by_runner_id` BIGINT UNSIGNED NULL,
  `leased_until`        DATETIME        NULL,
  `started_at`  DATETIME NULL,
  `finished_at` DATETIME NULL,
  `last_error`  TEXT     NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_runner_jobs_uid`         (`job_uid`),
  KEY `idx_runner_jobs_status_prio`       (`status`, `priority`),
  KEY `idx_runner_jobs_available`         (`available_at`),
  KEY `idx_runner_jobs_bot`               (`bot_id`),
  KEY `idx_runner_jobs_target`            (`target_runner_id`),
  KEY `idx_runner_jobs_lease`             (`leased_by_runner_id`, `leased_until`),
  CONSTRAINT `fk_runner_jobs_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_runner_jobs_target_runner`
    FOREIGN KEY (`target_runner_id`) REFERENCES `runners` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_runner_jobs_leased_by_runner`
    FOREIGN KEY (`leased_by_runner_id`) REFERENCES `runners` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT GUILDS (cache)
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_guilds` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`     BIGINT UNSIGNED NOT NULL,
  `guild_id`   BIGINT UNSIGNED NOT NULL,
  `guild_name` VARCHAR(150)    NULL,
  `is_owner`   TINYINT(1)      NOT NULL DEFAULT 0,
  `icon_hash`  VARCHAR(64)     NULL,
  `permissions` BIGINT UNSIGNED NULL,
  `added_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_guild`       (`bot_id`, `guild_id`),
  KEY `idx_bot_guilds_bot`        (`bot_id`),
  KEY `idx_bot_guilds_guild`      (`guild_id`),
  CONSTRAINT `fk_bot_guilds_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- GUILD SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `guild_settings` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `guild_id`     BIGINT UNSIGNED NOT NULL,
  `settings_json` JSON           NOT NULL,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_guild_settings_bot_guild` (`bot_id`, `guild_id`),
  KEY `idx_guild_settings_bot`   (`bot_id`),
  KEY `idx_guild_settings_guild` (`guild_id`),
  CONSTRAINT `fk_guild_settings_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- COMMANDS
-- =========================================================
CREATE TABLE IF NOT EXISTS `commands` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `command_key`  VARCHAR(120)    NOT NULL,
  `command_type` ENUM('predefined','moderation','custom','module') NOT NULL DEFAULT 'custom',
  `name`         VARCHAR(120)    NOT NULL DEFAULT '',
  `description`  VARCHAR(255)    NULL,
  `is_enabled`   TINYINT(1)      NOT NULL DEFAULT 1,
  `settings_json` JSON           NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_commands_bot_key`  (`bot_id`, `command_key`),
  KEY `idx_commands_bot`            (`bot_id`),
  KEY `idx_commands_bot_type`       (`bot_id`, `command_type`),
  CONSTRAINT `fk_commands_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- COMMAND REVISIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `command_revisions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `command_id` BIGINT UNSIGNED NOT NULL,
  `version`    INT UNSIGNED    NOT NULL,
  `status`     ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `nodes_json` JSON            NOT NULL,
  `edges_json` JSON            NOT NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_command_revisions_version` (`command_id`, `version`),
  KEY `idx_command_revisions_command`       (`command_id`),
  KEY `idx_command_revisions_status`        (`status`),
  CONSTRAINT `fk_command_revisions_command`
    FOREIGN KEY (`command_id`) REFERENCES `commands` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- COMMAND SHARE CODES
-- =========================================================
CREATE TABLE IF NOT EXISTS `command_share_codes` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`         VARCHAR(25)     NOT NULL,
  `payload_json` LONGTEXT        NOT NULL,
  `created_by`   BIGINT UNSIGNED NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`   DATETIME        NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_command_share_code`    (`code`),
  KEY `idx_command_share_created`       (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- REUSABLE MESSAGES
-- =========================================================
CREATE TABLE IF NOT EXISTS `reusable_messages` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `name`         VARCHAR(120)    NOT NULL,
  `content_json` JSON            NOT NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reusable_messages_bot_name` (`bot_id`, `name`),
  KEY `idx_reusable_messages_bot`            (`bot_id`),
  CONSTRAINT `fk_reusable_messages_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT LOGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `level`        ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
  `message`      TEXT            NOT NULL,
  `context_json` JSON            NULL,
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bot_logs_bot_time` (`bot_id`, `created_at`),
  KEY `idx_bot_logs_level`    (`level`),
  CONSTRAINT `fk_bot_logs_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- AUDIT LOG
-- =========================================================
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `action`        VARCHAR(80)     NOT NULL,
  `entity_type`   VARCHAR(80)     NOT NULL,
  `entity_id`     BIGINT UNSIGNED NULL,
  `meta_json`     JSON            NULL,
  `ip_address`    VARCHAR(45)     NULL,
  `user_agent`    VARCHAR(255)    NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_actor`  (`actor_user_id`),
  KEY `idx_audit_entity` (`entity_type`, `entity_id`),
  KEY `idx_audit_time`   (`created_at`),
  CONSTRAINT `fk_audit_actor`
    FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT METRICS (5-minute buckets)
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_metrics_5m` (
  `id`        BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `bot_id`    BIGINT UNSIGNED  NOT NULL,
  `bucket_at` DATETIME         NOT NULL,
  `uptime_ok` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `cmd_calls` INT UNSIGNED     NOT NULL DEFAULT 0,
  `errors`    INT UNSIGNED     NOT NULL DEFAULT 0,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_metrics_5m_bot_bucket` (`bot_id`, `bucket_at`),
  KEY `idx_bot_metrics_5m_bot_time`         (`bot_id`, `bucket_at`),
  CONSTRAINT `fk_bot_metrics_5m_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT AFK USERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_afk_users` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`     BIGINT UNSIGNED NOT NULL,
  `guild_id`   VARCHAR(32)     NOT NULL,
  `user_id`    VARCHAR(32)     NOT NULL,
  `reason`     VARCHAR(255)    NOT NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP       NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_afk` (`bot_id`, `guild_id`, `user_id`),
  KEY `idx_bot`   (`bot_id`),
  KEY `idx_guild` (`guild_id`),
  KEY `idx_user`  (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT CUSTOM COMMANDS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_custom_commands` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`             BIGINT UNSIGNED NOT NULL,
  `name`               VARCHAR(120)    NOT NULL,
  `slash_name`         VARCHAR(32)     NOT NULL,
  `description`        VARCHAR(255)    NULL,
  `is_enabled`         TINYINT(1)      NOT NULL DEFAULT 1,
  `group_name`         VARCHAR(80)     NULL DEFAULT NULL,
  `created_by_user_id` BIGINT UNSIGNED NULL,
  `updated_by_user_id` BIGINT UNSIGNED NULL,
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_custom_commands_bot_slug` (`bot_id`, `slash_name`),
  KEY `idx_bot_custom_commands_bot_id`         (`bot_id`),
  KEY `idx_bot_custom_commands_group`          (`bot_id`, `group_name`),
  KEY `idx_bot_custom_commands_enabled`        (`bot_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT CUSTOM COMMAND BUILDERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_custom_command_builders` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `custom_command_id` BIGINT UNSIGNED NOT NULL,
  `builder_json`      LONGTEXT        NOT NULL,
  `builder_version`   INT             NOT NULL DEFAULT 1,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_command_builder`   (`custom_command_id`),
  KEY `idx_custom_command_builder_command` (`custom_command_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT DATA VARIABLES
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_data_variables` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`        BIGINT UNSIGNED NOT NULL,
  `name`          VARCHAR(100)    NOT NULL,
  `reference`     VARCHAR(100)    NOT NULL,
  `var_type`      ENUM('text','number','user','channel','collection','object') NOT NULL DEFAULT 'text',
  `default_value` TEXT            NULL,
  `scope`         ENUM('global','server') NOT NULL DEFAULT 'server',
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_data_var` (`bot_id`, `reference`),
  KEY `idx_bot_data_variables` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT GLOBAL VARIABLES
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_global_variables` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`     INT UNSIGNED    NOT NULL,
  `var_key`    VARCHAR(64)     NOT NULL,
  `var_value`  TEXT            NULL DEFAULT NULL,
  `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_global_var`   (`bot_id`, `var_key`),
  KEY `idx_bot_global_vars`        (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT PLEX LIBRARIES
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_plex_libraries` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`             BIGINT UNSIGNED NOT NULL,
  `bot_id`              BIGINT UNSIGNED NOT NULL,
  `plex_account_id`     BIGINT UNSIGNED NOT NULL,
  `resource_identifier` VARCHAR(191)    NOT NULL,
  `server_name`         VARCHAR(191)    NOT NULL DEFAULT '',
  `library_key`         VARCHAR(64)     NOT NULL,
  `library_title`       VARCHAR(191)    NOT NULL,
  `library_type`        VARCHAR(50)     NOT NULL DEFAULT 'unknown',
  `is_allowed`          TINYINT(1)      NOT NULL DEFAULT 1,
  `plex_search_type`    INT             NULL DEFAULT NULL,
  `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_plex_library`          (`bot_id`, `resource_identifier`, `library_key`),
  KEY `idx_bot_plex_libraries_bot`          (`bot_id`),
  KEY `idx_bot_plex_libraries_account`      (`plex_account_id`),
  KEY `idx_bot_plex_libraries_allowed`      (`bot_id`, `is_allowed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT WEBHOOK KEYS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_webhook_keys` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `bot_id`     INT UNSIGNED  NOT NULL,
  `api_key`    VARCHAR(128)  NOT NULL,
  `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_key_bot` (`bot_id`),
  KEY `idx_webhook_api_key`       (`api_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT WEBHOOKS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_webhooks` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`     INT UNSIGNED    NOT NULL,
  `event_id`   VARCHAR(32)     NOT NULL,
  `event_name` VARCHAR(128)    NOT NULL DEFAULT '',
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_webhook_event` (`bot_id`, `event_id`),
  KEY `idx_bot_webhooks`        (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT WELCOMER SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_welcomer_settings` (
  `bot_id`             INT UNSIGNED  NOT NULL,
  `welcome_card`       TINYINT(1)    NOT NULL DEFAULT 0,
  `welcome_card_tpl`   VARCHAR(64)   NOT NULL DEFAULT '',
  `wc_channel`         VARCHAR(20)   NOT NULL DEFAULT '',
  `wc_bg`              VARCHAR(64)   NOT NULL DEFAULT 'default',
  `wc_bg_color`        VARCHAR(64)   NOT NULL DEFAULT 'brilliant_red',
  `wc_title_color`     VARCHAR(7)    NOT NULL DEFAULT '#ffffff',
  `wc_desc_color`      VARCHAR(7)    NOT NULL DEFAULT '#ffffff',
  `wc_avatar_color`    VARCHAR(7)    NOT NULL DEFAULT '#ffffff',
  `wc_title`           VARCHAR(255)  NOT NULL DEFAULT '{user_name}',
  `wc_desc`            VARCHAR(255)  NOT NULL DEFAULT 'Welcome to {server}',
  `wc_reactions`       VARCHAR(255)  NOT NULL DEFAULT '',
  `msg_join`           TINYINT(1)    NOT NULL DEFAULT 0,
  `msg_join_channel`   VARCHAR(20)   NOT NULL DEFAULT '',
  `msg_join_content`   TEXT          NULL,
  `dm_join`            TINYINT(1)    NOT NULL DEFAULT 0,
  `dm_join_content`    TEXT          NULL,
  `role_join`          TINYINT(1)    NOT NULL DEFAULT 0,
  `role_join_roles`    JSON          NULL,
  `msg_leave`          TINYINT(1)    NOT NULL DEFAULT 0,
  `msg_leave_channel`  VARCHAR(20)   NOT NULL DEFAULT '',
  `msg_leave_content`  TEXT          NULL,
  `msg_kick`           TINYINT(1)    NOT NULL DEFAULT 0,
  `msg_kick_channel`   VARCHAR(20)   NOT NULL DEFAULT '',
  `msg_kick_content`   TEXT          NULL,
  `msg_ban`            TINYINT(1)    NOT NULL DEFAULT 0,
  `msg_ban_channel`    VARCHAR(20)   NOT NULL DEFAULT '',
  `msg_ban_content`    TEXT          NULL,
  `event_joins`        TINYINT(1)    NOT NULL DEFAULT 1,
  `event_bans`         TINYINT(1)    NOT NULL DEFAULT 1,
  `event_leaves_kicks` TINYINT(1)    NOT NULL DEFAULT 1,
  `event_membership`   TINYINT(1)    NOT NULL DEFAULT 1,
  `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT WELCOMER COMMANDS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_welcomer_commands` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `bot_id`      INT UNSIGNED  NOT NULL,
  `command_key` VARCHAR(64)   NOT NULL,
  `description` VARCHAR(255)  NOT NULL DEFAULT '',
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wlcm_cmd_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT WELCOMER MODULE EVENTS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_welcomer_module_events` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `bot_id`      INT UNSIGNED  NOT NULL,
  `event_key`   VARCHAR(64)   NOT NULL,
  `description` VARCHAR(255)  NOT NULL DEFAULT '',
  `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wlcm_evt_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT MUSIC SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_music_settings` (
  `bot_id`               INT UNSIGNED     NOT NULL,
  `enabled`              TINYINT(1)       NOT NULL DEFAULT 1,
  `default_volume`       TINYINT UNSIGNED NOT NULL DEFAULT 50,
  `queue_limit`          SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  `dj_role_id`           VARCHAR(20)      NULL DEFAULT NULL,
  `music_channel_id`     VARCHAR(20)      NULL DEFAULT NULL,
  `leave_on_empty`       TINYINT(1)       NOT NULL DEFAULT 1,
  `leave_on_finish`      TINYINT(1)       NOT NULL DEFAULT 0,
  `announce_songs`       TINYINT(1)       NOT NULL DEFAULT 1,
  `src_youtube`          TINYINT(1)       NOT NULL DEFAULT 1,
  `src_spotify`          TINYINT(1)       NOT NULL DEFAULT 0,
  `src_soundcloud`       TINYINT(1)       NOT NULL DEFAULT 0,
  `src_deezer`           TINYINT(1)       NOT NULL DEFAULT 0,
  `src_apple_music`      TINYINT(1)       NOT NULL DEFAULT 0,
  `src_plex`             TINYINT(1)       NOT NULL DEFAULT 0,
  `spotify_client_id`    VARCHAR(128)     NULL DEFAULT NULL,
  `spotify_client_secret` VARCHAR(128)    NULL DEFAULT NULL,
  `updated_at`           TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT SOUNDBOARD SOUNDS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_soundboard_sounds` (
  `id`        INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `bot_id`    INT UNSIGNED     NOT NULL,
  `user_id`   INT UNSIGNED     NOT NULL,
  `name`      VARCHAR(64)      NOT NULL,
  `emoji`     VARCHAR(16)      NULL DEFAULT NULL,
  `volume`    TINYINT UNSIGNED NOT NULL DEFAULT 100,
  `filename`  VARCHAR(255)     NOT NULL,
  `filesize`  INT UNSIGNED     NOT NULL DEFAULT 0,
  `mime_type` VARCHAR(64)      NOT NULL DEFAULT 'audio/mpeg',
  `file_data` MEDIUMBLOB       NULL DEFAULT NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bot_soundboard` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT STATUS SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_status_settings` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           INT UNSIGNED NOT NULL,
  `mode`             ENUM('fixed','rotating','command','disabled')                           NOT NULL DEFAULT 'disabled',
  `presence_status`  ENUM('online','idle','dnd','invisible')                                 NOT NULL DEFAULT 'online',
  `status_type`      ENUM('watching','playing','listening','streaming','competing','custom')  NOT NULL DEFAULT 'playing',
  `status_text`      VARCHAR(128) NOT NULL DEFAULT '',
  `stream_url`       VARCHAR(255) NOT NULL DEFAULT '',
  `rotating_interval` INT UNSIGNED NOT NULL DEFAULT 60,
  `cmd_change_status` TINYINT(1)  NOT NULL DEFAULT 0,
  `event_restart`    TINYINT(1)   NOT NULL DEFAULT 1,
  `event_update`     TINYINT(1)   NOT NULL DEFAULT 1,
  `event_rotating`   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_id` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT STATUS ROTATIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_status_rotations` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`      INT UNSIGNED NOT NULL,
  `status_type` ENUM('watching','playing','listening','streaming','competing','custom') NOT NULL DEFAULT 'playing',
  `status_text` VARCHAR(128) NOT NULL DEFAULT '',
  `stream_url`  VARCHAR(255) NOT NULL DEFAULT '',
  `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_bot_id` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- SYSTEM YOUTUBE TOKEN
-- =========================================================
CREATE TABLE IF NOT EXISTS `system_youtube_token` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `access_token`  TEXT          NOT NULL,
  `refresh_token` VARCHAR(2048) NOT NULL DEFAULT '',
  `expires_at`    DATETIME      NOT NULL,
  `email`         VARCHAR(255)  NOT NULL DEFAULT '',
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- APPS (App Store registry)
-- =========================================================
CREATE TABLE IF NOT EXISTS `apps` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `app_key`      VARCHAR(64)  NOT NULL,
  `name`         VARCHAR(128) NOT NULL,
  `description`  TEXT         NULL,
  `version`      VARCHAR(32)  NOT NULL DEFAULT '1.0.0',
  `category`     ENUM('media','moderation','utility','fun','social','custom') NOT NULL DEFAULT 'custom',
  `icon_svg`     TEXT         NULL,
  `author`       VARCHAR(128) NOT NULL DEFAULT 'BotHub',
  `is_official`  TINYINT(1)   NOT NULL DEFAULT 0,
  `sidebar_view` VARCHAR(128) NOT NULL DEFAULT '',
  `schema_sql`   LONGTEXT     NULL,
  `db_tables`    JSON         NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_key` (`app_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- INSTALLED APPS
-- =========================================================
CREATE TABLE IF NOT EXISTS `installed_apps` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `app_key`      VARCHAR(64)  NOT NULL,
  `installed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `installed_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `status`       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_key` (`app_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- APP BOT SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `app_bot_settings` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `app_key` VARCHAR(64)  NOT NULL,
  `bot_id`  INT UNSIGNED NOT NULL,
  `enabled` TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_bot` (`app_key`, `bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT AI SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_ai_settings` (
  `bot_id`                   BIGINT UNSIGNED NOT NULL,
  `active_provider`          VARCHAR(50)     NOT NULL DEFAULT 'openai',
  `system_prompt`            TEXT            NULL DEFAULT NULL,
  `max_tokens`               INT             NOT NULL DEFAULT 1000,
  `temperature`              DECIMAL(3,2)    NOT NULL DEFAULT 0.70,
  `history_length`           INT             NOT NULL DEFAULT 10,
  `session_timeout_min`      INT             NOT NULL DEFAULT 30,
  `web_search_enabled`       TINYINT(1)      NOT NULL DEFAULT 0,
  `brave_api_key`            TEXT            NULL DEFAULT NULL,
  `searxng_url`              VARCHAR(500)    NULL DEFAULT NULL,
  `mention_enabled`          TINYINT(1)      NOT NULL DEFAULT 0,
  `mention_context_messages` INT             NOT NULL DEFAULT 10,
  `web_search_always`        TINYINT(1)      NOT NULL DEFAULT 0,
  PRIMARY KEY (`bot_id`),
  CONSTRAINT `fk_ai_settings_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT AI PROVIDERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_ai_providers` (
  `id`             BIGINT UNSIGNED AUTO_INCREMENT,
  `bot_id`         BIGINT UNSIGNED NOT NULL,
  `provider`       VARCHAR(50)     NOT NULL,
  `api_key`        TEXT            NULL DEFAULT NULL,
  `base_url`       VARCHAR(500)    NULL DEFAULT NULL,
  `selected_model` VARCHAR(255)    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_provider` (`bot_id`, `provider`),
  CONSTRAINT `fk_ai_providers_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT AI ALLOWED CHANNELS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_ai_allowed_channels` (
  `bot_id`     BIGINT UNSIGNED NOT NULL,
  `channel_id` VARCHAR(20)     NOT NULL,
  PRIMARY KEY (`bot_id`, `channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT AUTOMOD SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_automod_settings` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`         BIGINT UNSIGNED NOT NULL,
  `anti_invite`    TINYINT(1)      NOT NULL DEFAULT 0,
  `anti_links`     TINYINT(1)      NOT NULL DEFAULT 0,
  `anti_spam`      TINYINT(1)      NOT NULL DEFAULT 0,
  `spam_max_msg`   SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `spam_window_s`  SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `spam_action`    ENUM('delete','warn','kick','ban') NOT NULL DEFAULT 'delete',
  `link_channels`  JSON            NULL,
  `blacklist`      JSON            NULL,
  `log_channel_id` VARCHAR(32)     NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_automod_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT AUTORESPONDERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_autoresponders` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`              BIGINT UNSIGNED NOT NULL,
  `trigger_type`        ENUM('contains','starts_with','exact') NOT NULL DEFAULT 'contains',
  `keywords`            JSON NULL,
  `is_embed`            TINYINT(1)   NOT NULL DEFAULT 1,
  `plain_text`          TEXT         NULL,
  `embed_author`        VARCHAR(256) NOT NULL DEFAULT '',
  `embed_thumbnail`     VARCHAR(512) NOT NULL DEFAULT '',
  `embed_title`         VARCHAR(256) NOT NULL DEFAULT '',
  `embed_body`          TEXT         NULL,
  `embed_image`         VARCHAR(512) NOT NULL DEFAULT '',
  `embed_color`         VARCHAR(16)  NOT NULL DEFAULT '#ef4444',
  `embed_url`           VARCHAR(512) NOT NULL DEFAULT '',
  `channel_cooldown`    INT          NOT NULL DEFAULT 10,
  `mention_user`        TINYINT(1)   NOT NULL DEFAULT 1,
  `channel_filter_type` ENUM('all_except','selected') NOT NULL DEFAULT 'all_except',
  `filtered_channels`   JSON NULL,
  `role_filter_type`    ENUM('all_except','selected') NOT NULL DEFAULT 'all_except',
  `filtered_roles`      JSON NULL,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ar_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT AUTORESPONDER COOLDOWNS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_autoresponder_cooldowns` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ar_id`        BIGINT UNSIGNED NOT NULL,
  `channel_id`   VARCHAR(32)     NOT NULL,
  `last_sent_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ar_cooldown` (`ar_id`, `channel_id`),
  KEY `idx_ar_cd_ar`          (`ar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT AUTO REACT SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_auto_react_settings` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `enabled_channels` JSON NULL,
  `reaction_emojis`  JSON NULL,
  `ignore_embeds`    TINYINT(1) NOT NULL DEFAULT 1,
  `allowed_roles`    JSON NULL,
  `check_words`      JSON NULL,
  `evt_handler`      TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ar_settings_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT COUNTING SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_counting_settings` (
  `bot_id`             BIGINT UNSIGNED NOT NULL,
  `guild_id`           VARCHAR(20)     NOT NULL,
  `channel_id`         VARCHAR(20)     NULL DEFAULT NULL,
  `mode`               ENUM('normal','webhook') NOT NULL DEFAULT 'normal',
  `reactions_enabled`  TINYINT(1)      NOT NULL DEFAULT 1,
  `reaction_emoji`     VARCHAR(100)    NOT NULL DEFAULT '✅',
  `allow_multiple`     TINYINT(1)      NOT NULL DEFAULT 0,
  `cooldown_enabled`   TINYINT(1)      NOT NULL DEFAULT 0,
  `return_errors`      TINYINT(1)      NOT NULL DEFAULT 1,
  `error_wrong_msg`    TEXT            NULL DEFAULT NULL,
  `error_twice_msg`    TEXT            NULL DEFAULT NULL,
  `error_cooldown_msg` TEXT            NULL DEFAULT NULL,
  UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT COUNTING STATE
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_counting_state` (
  `bot_id`          BIGINT UNSIGNED NOT NULL,
  `guild_id`        VARCHAR(20)     NOT NULL,
  `current_count`   INT             NOT NULL DEFAULT 0,
  `last_user_id`    VARCHAR(20)     NULL DEFAULT NULL,
  `last_message_id` VARCHAR(20)     NULL DEFAULT NULL,
  `last_count_at`   DATETIME        NULL DEFAULT NULL,
  UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT GIVEAWAYS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_giveaways` (
  `id`           INT             NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `guild_id`     VARCHAR(20)     NOT NULL,
  `channel_id`   VARCHAR(20)     NOT NULL,
  `message_id`   VARCHAR(20)     NULL DEFAULT NULL,
  `prize`        VARCHAR(255)    NOT NULL,
  `winner_count` INT             NOT NULL DEFAULT 1,
  `ends_at`      DATETIME        NOT NULL,
  `host_id`      VARCHAR(20)     NOT NULL,
  `is_active`    TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`   DATETIME        DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bot_id`    (`bot_id`),
  KEY `idx_guild_id`  (`guild_id`),
  KEY `idx_ends_at`   (`ends_at`),
  CONSTRAINT `fk_giveaways_bot`
    FOREIGN KEY (`bot_id`) REFERENCES `bot_instances` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT GIVEAWAY PARTICIPANTS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_giveaway_participants` (
  `id`           INT             NOT NULL AUTO_INCREMENT,
  `giveaway_id`  INT             NOT NULL,
  `user_id`      VARCHAR(20)     NOT NULL,
  `joined_at`    DATETIME        DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_giveaway_user` (`giveaway_id`, `user_id`),
  CONSTRAINT `fk_participants_giveaway`
    FOREIGN KEY (`giveaway_id`) REFERENCES `bot_giveaways` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT GIVEAWAY SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_giveaway_settings` (
  `bot_id`            BIGINT UNSIGNED NOT NULL,
  `winner_message`    TEXT            NULL,
  `no_winner_message` TEXT            NULL,
  PRIMARY KEY (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT INVITE TRACKER SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_invite_tracker_settings` (
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `guild_id`     VARCHAR(20)     NOT NULL DEFAULT '',
  `enabled`      TINYINT(1)      NOT NULL DEFAULT 1,
  `channel_id`   VARCHAR(20)     NOT NULL DEFAULT '',
  `join_message` TEXT            NULL,
  `updated_at`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT INVITE STATS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_invite_stats` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `guild_id`     VARCHAR(20)     NOT NULL,
  `inviter_id`   VARCHAR(20)     NOT NULL,
  `inviter_name` VARCHAR(100)    NOT NULL DEFAULT '',
  `invite_code`  VARCHAR(20)     NOT NULL,
  `uses`         INT UNSIGNED    NOT NULL DEFAULT 0,
  `last_used_at` DATETIME        NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_guild_code` (`bot_id`, `guild_id`, `invite_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT POLLS SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_polls_settings` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`              BIGINT UNSIGNED NOT NULL,
  `enabled`             TINYINT(1)   NOT NULL DEFAULT 1,
  `manager_roles`       JSON NULL,
  `whitelisted_channels` JSON NULL,
  `blacklisted_roles`   JSON NULL,
  `single_choice`       TINYINT(1)   NOT NULL DEFAULT 0,
  `embed_title`         VARCHAR(256) NOT NULL DEFAULT '🗳️ Poll - {poll.question}',
  `embed_footer`        VARCHAR(512) NOT NULL DEFAULT 'Participate in the poll by reacting with one of the options specified below. We thank you for your feedback!',
  `embed_color`         VARCHAR(16)  NOT NULL DEFAULT '#EE3636',
  `show_poster_name`    TINYINT(1)   NOT NULL DEFAULT 1,
  `choice_reactions`    JSON NULL,
  `cmd_poll_list`       TINYINT(1)   NOT NULL DEFAULT 1,
  `cmd_poll_delete`     TINYINT(1)   NOT NULL DEFAULT 1,
  `evt_polls_handler`   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_polls_settings_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT POLLS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_polls` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `guild_id`         VARCHAR(32)  NOT NULL,
  `channel_id`       VARCHAR(32)  NOT NULL DEFAULT '',
  `message_id`       VARCHAR(32)  NOT NULL DEFAULT '',
  `question`         VARCHAR(512) NOT NULL,
  `choices`          JSON NULL,
  `creator_user_id`  VARCHAR(32)  NOT NULL DEFAULT '',
  `creator_username` VARCHAR(100) NOT NULL DEFAULT '',
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_polls_bot`     (`bot_id`),
  KEY `idx_polls_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT POLL VOTES
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_poll_votes` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `poll_id`  BIGINT UNSIGNED NOT NULL,
  `user_id`  VARCHAR(32)  NOT NULL,
  `emoji`    VARCHAR(128) NOT NULL,
  `voted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_poll_vote`   (`poll_id`, `user_id`, `emoji`),
  KEY `idx_poll_votes`        (`poll_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT REACTION ROLES
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_reaction_roles` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `message_id`       VARCHAR(32)  NOT NULL,
  `channel_id`       VARCHAR(32)  NOT NULL DEFAULT '',
  `emoji`            VARCHAR(128) NOT NULL DEFAULT '',
  `roles_to_add`     JSON NULL,
  `roles_to_remove`  JSON NULL,
  `blacklisted_roles` JSON NULL,
  `restrict_one`     TINYINT(1)   NOT NULL DEFAULT 0,
  `remove_reaction`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rr_bot`     (`bot_id`),
  KEY `idx_rr_message` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT STATISTIC CHANNELS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_statistic_channels` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED NOT NULL,
  `guild_id`     VARCHAR(32)  NOT NULL DEFAULT '',
  `channel_id`   VARCHAR(32)  NOT NULL DEFAULT '',
  `channel_name` VARCHAR(100) NOT NULL DEFAULT 'Members: {value}',
  `stat_type`    VARCHAR(50)  NOT NULL DEFAULT 'total_members',
  `auto_lock`    TINYINT(1)   NOT NULL DEFAULT 1,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `cached_value` VARCHAR(50)  NOT NULL DEFAULT '',
  `updated_at`   DATETIME     NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stat_bot`     (`bot_id`),
  KEY `idx_stat_channel` (`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT STICKY SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_sticky_settings` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`          BIGINT UNSIGNED NOT NULL,
  `manager_role_id` VARCHAR(32)  NOT NULL DEFAULT '',
  `is_embed`        TINYINT(1)   NOT NULL DEFAULT 1,
  `plain_text`      TEXT         NULL,
  `embed_author`    VARCHAR(256) NOT NULL DEFAULT '',
  `embed_thumbnail` VARCHAR(512) NOT NULL DEFAULT '',
  `embed_title`     VARCHAR(256) NOT NULL DEFAULT 'Sticky Messages',
  `embed_body`      TEXT         NULL,
  `embed_image`     VARCHAR(512) NOT NULL DEFAULT '',
  `embed_color`     VARCHAR(16)  NOT NULL DEFAULT '#f48342',
  `embed_url`       VARCHAR(512) NOT NULL DEFAULT '',
  `embed_footer`    VARCHAR(512) NOT NULL DEFAULT 'Sticky messages module',
  `repost_count`    INT          NOT NULL DEFAULT 10,
  `show_author`     TINYINT(1)   NOT NULL DEFAULT 1,
  `add_reaction`    TINYINT(1)   NOT NULL DEFAULT 1,
  `reaction_emoji`  VARCHAR(128) NOT NULL DEFAULT '👍',
  `evt_handler`     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sticky_settings_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT STICKY CHANNELS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_sticky_channels` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`          BIGINT UNSIGNED NOT NULL,
  `channel_id`      VARCHAR(32)  NOT NULL,
  `last_message_id` VARCHAR(32)  NOT NULL DEFAULT '',
  `message_count`   INT          NOT NULL DEFAULT 0,
  `posted_by`       VARCHAR(32)  NOT NULL DEFAULT '',
  `is_embed`        TINYINT(1)   NOT NULL DEFAULT 1,
  `plain_text`      TEXT         NULL,
  `embed_author`    VARCHAR(256) NOT NULL DEFAULT '',
  `embed_thumbnail` VARCHAR(512) NOT NULL DEFAULT '',
  `embed_title`     VARCHAR(256) NOT NULL DEFAULT '',
  `embed_body`      TEXT         NULL,
  `embed_image`     VARCHAR(512) NOT NULL DEFAULT '',
  `embed_color`     VARCHAR(16)  NOT NULL DEFAULT '#f48342',
  `embed_url`       VARCHAR(512) NOT NULL DEFAULT '',
  `embed_footer`    VARCHAR(512) NOT NULL DEFAULT '',
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sticky_channel` (`bot_id`, `channel_id`),
  KEY `idx_sticky_bot`           (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT TIMED MESSAGE SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_timed_message_settings` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`      BIGINT UNSIGNED NOT NULL,
  `evt_handler` TINYINT(1)      NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_timed_settings_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT TIMED MESSAGES
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_timed_messages` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `guild_id`         VARCHAR(32)  NOT NULL DEFAULT '',
  `name`             VARCHAR(100) NOT NULL,
  `channel_id`       VARCHAR(32)  NOT NULL DEFAULT '',
  `interval_days`    INT          NOT NULL DEFAULT 0,
  `interval_hours`   INT          NOT NULL DEFAULT 1,
  `interval_minutes` INT          NOT NULL DEFAULT 0,
  `is_embed`         TINYINT(1)   NOT NULL DEFAULT 1,
  `plain_text`       TEXT         NULL,
  `embed_author`     VARCHAR(256) NOT NULL DEFAULT '',
  `embed_thumbnail`  VARCHAR(512) NOT NULL DEFAULT '',
  `embed_title`      VARCHAR(256) NOT NULL DEFAULT '',
  `embed_body`       TEXT         NULL,
  `embed_image`      VARCHAR(512) NOT NULL DEFAULT '',
  `embed_color`      VARCHAR(16)  NOT NULL DEFAULT '#ef4444',
  `embed_url`        VARCHAR(512) NOT NULL DEFAULT '',
  `block_stacked`    TINYINT(1)   NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `last_sent_at`     DATETIME     NULL,
  `next_send_at`     DATETIME     NULL,
  `last_message_id`  VARCHAR(32)  NOT NULL DEFAULT '',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_timed_bot`  (`bot_id`),
  KEY `idx_timed_next` (`next_send_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT VERIFICATION SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_verification_settings` (
  `bot_id`            BIGINT UNSIGNED NOT NULL,
  `guild_id`          VARCHAR(20)  NOT NULL,
  `verification_type` ENUM('button','captcha') NOT NULL DEFAULT 'captcha',
  `channel_id`        VARCHAR(20)  NULL DEFAULT NULL,
  `verified_role_id`  VARCHAR(20)  NULL DEFAULT NULL,
  `embed_author`      VARCHAR(256) NULL DEFAULT NULL,
  `embed_title`       VARCHAR(256) NULL DEFAULT NULL,
  `embed_body`        TEXT         NULL DEFAULT NULL,
  `embed_image`       VARCHAR(512) NULL DEFAULT NULL,
  `embed_footer`      VARCHAR(256) NULL DEFAULT NULL,
  `embed_color`       VARCHAR(7)   NOT NULL DEFAULT '#5ba9e4',
  `embed_url`         VARCHAR(512) NULL DEFAULT NULL,
  `button_name`       VARCHAR(80)  NOT NULL DEFAULT 'Start Verification',
  `log_channel_id`    VARCHAR(20)  NULL DEFAULT NULL,
  `success_message`   TEXT         NULL DEFAULT NULL,
  `max_attempts`      INT          NOT NULL DEFAULT 3,
  `time_limit_sec`    INT          NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT VERIFICATION PENDING
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_verification_pending` (
  `bot_id`     BIGINT UNSIGNED NOT NULL,
  `guild_id`   VARCHAR(20)     NOT NULL,
  `user_id`    VARCHAR(20)     NOT NULL,
  `joined_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `kick_after` DATETIME        NULL DEFAULT NULL,
  `attempts`   INT             NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_bot_guild_user` (`bot_id`, `guild_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_settings` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`            BIGINT UNSIGNED NOT NULL,
  `guild_id`          BIGINT UNSIGNED NOT NULL,
  `currency_symbol`   VARCHAR(32)     NOT NULL DEFAULT '🪙',
  `currency_name`     VARCHAR(50)     NOT NULL DEFAULT 'Coins',
  `daily_amount`      INT UNSIGNED    NOT NULL DEFAULT 200,
  `work_min`          INT UNSIGNED    NOT NULL DEFAULT 50,
  `work_max`          INT UNSIGNED    NOT NULL DEFAULT 150,
  `bank_interest_rate` DECIMAL(5,2)   NOT NULL DEFAULT 0.00,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eco_settings` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY WALLETS
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_wallets` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`     BIGINT UNSIGNED NOT NULL,
  `guild_id`   BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `wallet`     BIGINT          NOT NULL DEFAULT 0,
  `bank`       BIGINT          NOT NULL DEFAULT 0,
  `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eco_wallet` (`bot_id`, `guild_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY COOLDOWNS
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_cooldowns` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`     BIGINT UNSIGNED NOT NULL,
  `guild_id`   BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `action`     VARCHAR(50)     NOT NULL,
  `expires_at` DATETIME        NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eco_cooldown` (`bot_id`, `guild_id`, `user_id`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY CURRENCIES
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_currencies` (
  `id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `bot_id`     BIGINT UNSIGNED  NOT NULL,
  `guild_id`   BIGINT UNSIGNED  NOT NULL,
  `symbol`     VARCHAR(32)      NOT NULL DEFAULT '🪙',
  `name`       VARCHAR(50)      NOT NULL DEFAULT 'Coins',
  `is_default` TINYINT(1)       NOT NULL DEFAULT 0,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eco_currencies` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY SHOP ITEMS
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_shop_items` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`      BIGINT UNSIGNED NOT NULL,
  `guild_id`    BIGINT UNSIGNED NOT NULL,
  `name`        VARCHAR(80)     NOT NULL,
  `description` VARCHAR(255)    NULL,
  `price`       INT UNSIGNED    NOT NULL DEFAULT 100,
  `emoji`       VARCHAR(32)     NOT NULL DEFAULT '🎁',
  `stock`       INT             NOT NULL DEFAULT -1,
  `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eco_shop` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY INVENTORY
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_inventory` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`   BIGINT UNSIGNED NOT NULL,
  `guild_id` BIGINT UNSIGNED NOT NULL,
  `user_id`  BIGINT UNSIGNED NOT NULL,
  `item_id`  BIGINT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED    NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eco_inv` (`bot_id`, `guild_id`, `user_id`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY JOBS
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_jobs` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `guild_id`         BIGINT UNSIGNED NOT NULL,
  `name`             VARCHAR(80)     NOT NULL,
  `description`      VARCHAR(255)    NULL,
  `min_wage`         INT UNSIGNED    NOT NULL DEFAULT 100,
  `max_wage`         INT UNSIGNED    NOT NULL DEFAULT 200,
  `cooldown_seconds` INT UNSIGNED    NOT NULL DEFAULT 3600,
  `emoji`            VARCHAR(32)     NOT NULL DEFAULT '💼',
  `is_active`        TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_eco_jobs` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY USER JOBS
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_user_jobs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`         BIGINT UNSIGNED NOT NULL,
  `guild_id`       BIGINT UNSIGNED NOT NULL,
  `user_id`        BIGINT UNSIGNED NOT NULL,
  `job_id`         BIGINT UNSIGNED NOT NULL,
  `assigned_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_worked_at` DATETIME        NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_eco_user_job` (`bot_id`, `guild_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- ECONOMY HANGMAN WORDS
-- =========================================================
CREATE TABLE IF NOT EXISTS `eco_hangman_words` (
  `id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id` BIGINT UNSIGNED NOT NULL,
  `word`   VARCHAR(64)     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_hangman_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- LEVELING SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `leveling_settings` (
  `bot_id`               INT UNSIGNED NOT NULL,
  `embed_color`          VARCHAR(7)   NOT NULL DEFAULT '#f45142',
  `max_level`            INT UNSIGNED NOT NULL DEFAULT 0,
  `xp_per_level`         INT UNSIGNED NOT NULL DEFAULT 50,
  `clear_on_leave`       TINYINT(1)   NOT NULL DEFAULT 1,
  `levelup_message`      ENUM('disabled','current_channel','dm') NOT NULL DEFAULT 'disabled',
  `card_type`            ENUM('embed') NOT NULL DEFAULT 'embed',
  `msg_xp_min`           INT UNSIGNED NOT NULL DEFAULT 10,
  `msg_xp_max`           INT UNSIGNED NOT NULL DEFAULT 25,
  `msg_cooldown`         INT UNSIGNED NOT NULL DEFAULT 15,
  `voice_xp_enabled`     TINYINT(1)   NOT NULL DEFAULT 0,
  `voice_xp_per_minute`  INT UNSIGNED NOT NULL DEFAULT 5,
  `sum_boosts`           TINYINT(1)   NOT NULL DEFAULT 0,
  `randomize_boosts`     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- =========================================================
-- LEVELING USERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `leveling_users` (
  `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `bot_id`          INT UNSIGNED    NOT NULL,
  `guild_id`        VARCHAR(20)     NOT NULL,
  `user_id`         VARCHAR(20)     NOT NULL,
  `xp`              BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `level`           INT UNSIGNED    NOT NULL DEFAULT 0,
  `total_xp`        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_message_at` DATETIME        NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_guild_user`  (`bot_id`, `guild_id`, `user_id`),
  KEY `idx_leaderboard`           (`bot_id`, `guild_id`, `total_xp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- =========================================================
-- LEVELING BOOSTERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `leveling_boosters` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`       INT UNSIGNED NOT NULL,
  `booster_type` ENUM('role','channel') NOT NULL DEFAULT 'role',
  `target_id`    VARCHAR(20)  NOT NULL,
  `percentage`   INT          NOT NULL DEFAULT 10,
  PRIMARY KEY (`id`),
  KEY `idx_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- =========================================================
-- LEVELING VOICE SESSIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `leveling_voice_sessions` (
  `bot_id`     INT UNSIGNED NOT NULL,
  `guild_id`   VARCHAR(20)  NOT NULL,
  `user_id`    VARCHAR(20)  NOT NULL,
  `channel_id` VARCHAR(20)  NOT NULL,
  `joined_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`bot_id`, `guild_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- =========================================================
-- POKEMIA USERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `pokemia_users` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`        BIGINT UNSIGNED NOT NULL,
  `user_id`       VARCHAR(32)     NOT NULL,
  `selected_id`   BIGINT UNSIGNED NULL,
  `balance`       INT UNSIGNED    NOT NULL DEFAULT 0,
  `pokedex_json`  JSON            NULL,
  `settings_json` JSON            NULL,
  `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pokemia_user` (`bot_id`, `user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- POKEMIA POKEMON
-- =========================================================
CREATE TABLE IF NOT EXISTS `pokemia_pokemon` (
  `id`             BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `bot_id`         BIGINT UNSIGNED   NOT NULL,
  `owner_id`       VARCHAR(32)       NOT NULL,
  `species_id`     SMALLINT UNSIGNED NOT NULL,
  `level`          TINYINT UNSIGNED  NOT NULL DEFAULT 5,
  `xp`             INT UNSIGNED      NOT NULL DEFAULT 0,
  `nature`         VARCHAR(16)       NOT NULL DEFAULT 'Hardy',
  `shiny`          TINYINT(1)        NOT NULL DEFAULT 0,
  `gender`         VARCHAR(8)        NOT NULL DEFAULT 'male',
  `iv_hp`          TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `iv_atk`         TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `iv_def`         TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `iv_spatk`       TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `iv_spdef`       TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `iv_spd`         TINYINT UNSIGNED  NOT NULL DEFAULT 0,
  `nickname`       VARCHAR(32)       NULL,
  `moves_json`     JSON              NULL,
  `caught_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `training_until` DATETIME          NULL DEFAULT NULL,
  `training_xp`    INT UNSIGNED      NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_pkm_owner`   (`bot_id`, `owner_id`),
  KEY `idx_pkm_species` (`bot_id`, `species_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- POKEMIA GUILD CONFIG
-- =========================================================
CREATE TABLE IF NOT EXISTS `pokemia_guild_config` (
  `id`           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `bot_id`       BIGINT UNSIGNED   NOT NULL,
  `guild_id`     VARCHAR(32)       NOT NULL,
  `spawn_channel` VARCHAR(32)      NULL,
  `spawn_rate`   SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pkm_guild` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- POKEMIA SPAWN
-- =========================================================
CREATE TABLE IF NOT EXISTS `pokemia_spawn` (
  `id`         BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `bot_id`     BIGINT UNSIGNED   NOT NULL,
  `guild_id`   VARCHAR(32)       NOT NULL,
  `channel_id` VARCHAR(32)       NOT NULL,
  `species_id` SMALLINT UNSIGNED NOT NULL,
  `caught`     TINYINT(1)        NOT NULL DEFAULT 0,
  `spawned_at` DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pkm_spawn` (`bot_id`, `guild_id`, `caught`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TWITCH NOTIFICATIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `twitch_notifications` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `guild_id`         VARCHAR(30)     NOT NULL,
  `channel_id`       VARCHAR(30)     NOT NULL,
  `streamer_login`   VARCHAR(50)     NOT NULL,
  `streamer_id`      VARCHAR(20)     NOT NULL DEFAULT '',
  `custom_message`   TEXT            NULL,
  `is_enabled`       TINYINT(1)      NOT NULL DEFAULT 1,
  `is_live`          TINYINT(1)      NOT NULL DEFAULT 0,
  `last_notified_at` DATETIME        NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_twitch_notify`   (`bot_id`, `guild_id`, `streamer_login`),
  KEY `idx_twitch_notify_bot`     (`bot_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- TWITCH APP CONFIG
-- =========================================================
CREATE TABLE IF NOT EXISTS `twitch_app_config` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `config_key`   VARCHAR(64)  NOT NULL,
  `config_value` TEXT         NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT BIRTHDAYS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_birthdays` (
  `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `bot_id`      BIGINT UNSIGNED  NOT NULL,
  `guild_id`    VARCHAR(32)      NOT NULL,
  `user_id`     VARCHAR(32)      NOT NULL,
  `username`    VARCHAR(100)     NOT NULL DEFAULT '',
  `birth_day`   TINYINT UNSIGNED NOT NULL,
  `birth_month` TINYINT UNSIGNED NOT NULL,
  `created_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_birthday_user`    (`bot_id`, `guild_id`, `user_id`),
  KEY `idx_birthday_bot_guild`     (`bot_id`, `guild_id`),
  KEY `idx_birthday_month_day`     (`birth_month`, `birth_day`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT BIRTHDAY SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_birthday_settings` (
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `guild_id`         VARCHAR(32)     NOT NULL,
  `announce_channel` VARCHAR(32)     NOT NULL DEFAULT '',
  `announce_message` VARCHAR(512)    NOT NULL DEFAULT 'Alles Gute zum Geburtstag {user}! 🎂🎉',
  `is_enabled`       TINYINT(1)      NOT NULL DEFAULT 1,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT TEMP VOICE SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_temp_voice_settings` (
  `id`                 INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `bot_id`             INT UNSIGNED     NOT NULL,
  `guild_id`           VARCHAR(20)      NOT NULL DEFAULT '',
  `trigger_channel_id` VARCHAR(20)      NOT NULL DEFAULT '',
  `category_id`        VARCHAR(20)      NOT NULL DEFAULT '',
  `channel_name`       VARCHAR(100)     NOT NULL DEFAULT 'Temp #{n}',
  `user_limit`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `bitrate`            INT UNSIGNED     NOT NULL DEFAULT 64000,
  `is_enabled`         TINYINT(1)       NOT NULL DEFAULT 1,
  `created_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bot_guild` (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT TEMP VOICE CHANNELS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_temp_voice_channels` (
  `id`          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  `bot_id`      INT UNSIGNED      NOT NULL,
  `guild_id`    VARCHAR(20)       NOT NULL,
  `channel_id`  VARCHAR(20)       NOT NULL,
  `owner_id`    VARCHAR(20)       NOT NULL DEFAULT '',
  `channel_num` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at`  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_channel` (`bot_id`, `guild_id`, `channel_id`),
  KEY `idx_bot_guild`     (`bot_id`, `guild_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- KICK NOTIFICATIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `kick_notifications` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `guild_id`         VARCHAR(30)     NOT NULL DEFAULT '',
  `channel_id`       VARCHAR(30)     NOT NULL,
  `streamer_slug`    VARCHAR(60)     NOT NULL,
  `custom_message`   TEXT            NULL,
  `ping_role_id`     VARCHAR(30)     NOT NULL DEFAULT '',
  `is_enabled`       TINYINT(1)      NOT NULL DEFAULT 1,
  `is_live`          TINYINT(1)      NOT NULL DEFAULT 0,
  `last_notified_at` DATETIME        NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kick_notify` (`bot_id`, `guild_id`, `streamer_slug`),
  KEY `idx_kick_notify_bot` (`bot_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- YOUTUBE NOTIFICATIONS
-- =========================================================
CREATE TABLE IF NOT EXISTS `youtube_notifications` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `guild_id`         VARCHAR(30)     NOT NULL DEFAULT '',
  `channel_id`       VARCHAR(30)     NOT NULL,
  `yt_channel_id`    VARCHAR(64)     NOT NULL,
  `yt_channel_name`  VARCHAR(128)    NOT NULL DEFAULT '',
  `ping_role_id`     VARCHAR(30)     NOT NULL DEFAULT '',
  `custom_message`   TEXT            NULL,
  `is_enabled`       TINYINT(1)      NOT NULL DEFAULT 1,
  `last_video_id`    VARCHAR(64)     NOT NULL DEFAULT '',
  `last_notified_at` DATETIME        NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_yt_notify` (`bot_id`, `guild_id`, `yt_channel_id`),
  KEY `idx_yt_notify_bot` (`bot_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- FREE GAMES SETTINGS
-- =========================================================
CREATE TABLE IF NOT EXISTS `free_games_settings` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `channel_id`       VARCHAR(30)     NOT NULL DEFAULT '',
  `ping_role_id`     VARCHAR(30)     NOT NULL DEFAULT '',
  `epic_enabled`     TINYINT(1)      NOT NULL DEFAULT 1,
  `steam_enabled`    TINYINT(1)      NOT NULL DEFAULT 1,
  `is_enabled`       TINYINT(1)      NOT NULL DEFAULT 1,
  `schedule_enabled` TINYINT(1)      NOT NULL DEFAULT 0,
  `schedule_time`    VARCHAR(5)      NOT NULL DEFAULT '09:00',
  `schedule_days`    VARCHAR(100)    NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
  `last_game_ids`    TEXT            NULL,
  `last_checked_at`  DATETIME        NULL,
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fg_bot` (`bot_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT CUSTOM EVENTS
-- =========================================================
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

-- =========================================================
-- BOT CUSTOM EVENT BUILDERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_custom_event_builders` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `custom_event_id` BIGINT UNSIGNED NOT NULL,
  `builder_json`    LONGTEXT        NOT NULL,
  `builder_version` INT UNSIGNED    NOT NULL DEFAULT 1,
  `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ceb_event` (`custom_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT TIMED EVENTS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_timed_events` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`             BIGINT UNSIGNED NOT NULL,
  `name`               VARCHAR(120)    NOT NULL DEFAULT '',
  `description`        VARCHAR(255)    NOT NULL DEFAULT '',
  `event_type`         ENUM('interval','schedule') NOT NULL DEFAULT 'interval',
  `interval_seconds`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `interval_minutes`   INT UNSIGNED    NOT NULL DEFAULT 0,
  `interval_hours`     INT UNSIGNED    NOT NULL DEFAULT 0,
  `interval_days`      INT UNSIGNED    NOT NULL DEFAULT 0,
  `week_days`          VARCHAR(64)     NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
  `schedule_time`      VARCHAR(5)      NOT NULL DEFAULT '00:00',
  `schedule_days`      VARCHAR(64)     NOT NULL DEFAULT 'Mon,Tue,Wed,Thu,Fri,Sat,Sun',
  `group_name`         VARCHAR(80)     NULL DEFAULT NULL,
  `is_enabled`         TINYINT(1)      NOT NULL DEFAULT 1,
  `created_by_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_te_bot`     (`bot_id`),
  KEY `idx_te_enabled` (`bot_id`, `is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- BOT TIMED EVENT BUILDERS
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_timed_event_builders` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id`     BIGINT UNSIGNED NOT NULL,
  `builder_json` MEDIUMTEXT      NOT NULL,
  `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_te_builder_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================================================
-- MESSAGE BUILDER
-- =========================================================
CREATE TABLE IF NOT EXISTS `bot_message_templates` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bot_id`           BIGINT UNSIGNED NOT NULL,
  `name`             VARCHAR(100)    NOT NULL,
  `tag`              VARCHAR(50)     NOT NULL DEFAULT '',
  `is_embed`         TINYINT(1)      NOT NULL DEFAULT 1,
  `plain_text`       TEXT            NULL,
  `embed_author`     VARCHAR(256)    NOT NULL DEFAULT '',
  `embed_thumbnail`  VARCHAR(512)    NOT NULL DEFAULT '',
  `embed_title`      VARCHAR(256)    NOT NULL DEFAULT '',
  `embed_body`       TEXT            NULL,
  `embed_image`      VARCHAR(512)    NOT NULL DEFAULT '',
  `embed_color`      VARCHAR(16)     NOT NULL DEFAULT '#5865f2',
  `embed_url`        VARCHAR(512)    NOT NULL DEFAULT '',
  `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_mb_bot`  (`bot_id`),
  UNIQUE KEY `uq_mb_bot_name` (`bot_id`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =========================================================
-- DEFAULT DATA
-- =========================================================
INSERT INTO `admin_settings` (`setting_key`, `setting_value`) VALUES
('allow_registration',      '1'),
('core_ping_interval',      '60'),
('google_oauth_client_id',  ''),
('google_oauth_client_secret', '')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

INSERT INTO `apps` (`app_key`, `name`, `description`, `version`, `category`, `icon_svg`, `author`, `is_official`, `sidebar_view`, `schema_sql`, `db_tables`) VALUES
('plex', 'Plex Integration', 'Stream Plex-Bibliotheken direkt über den Bot. Unterstützt Zufallswiedergabe, Statistiken und Bibliotheksverwaltung.', '1.0.0', 'media', '<path d="M3 2h10.5a.5.5 0 0 1 .354.854L9.707 7l4.147 4.146A.5.5 0 0 1 13.5 12H3a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Zm1 2v6h8.293L8.586 7.293a.414.414 0 0 1 0-.586L12.293 3H4Z"/>', 'BotHub', 1, 'plex', '', '["user_plex_accounts","user_plex_servers","bot_plex_libraries"]'),
('soundboard', 'Soundboard', 'Spiele Audiodateien in Sprachkanälen ab. Verwalte eine Bibliothek aus Sound-Clips direkt im Dashboard.', '1.0.0', 'fun', '<path d="M1 2.5A1.5 1.5 0 0 1 2.5 1h3A1.5 1.5 0 0 1 7 2.5v3A1.5 1.5 0 0 1 5.5 7h-3A1.5 1.5 0 0 1 1 5.5v-3Zm8 0A1.5 1.5 0 0 1 10.5 1h3A1.5 1.5 0 0 1 15 2.5v3A1.5 1.5 0 0 1 13.5 7h-3A1.5 1.5 0 0 1 9 5.5v-3Zm-8 8A1.5 1.5 0 0 1 2.5 9h3A1.5 1.5 0 0 1 7 10.5v3A1.5 1.5 0 0 1 5.5 15h-3A1.5 1.5 0 0 1 1 13.5v-3Zm8 0A1.5 1.5 0 0 1 10.5 9h3A1.5 1.5 0 0 1 15 10.5v3A1.5 1.5 0 0 1 13.5 15h-3A1.5 1.5 0 0 1 9 13.5v-3Z"/>', 'BotHub', 1, 'soundboard', '', '["bot_soundboard_sounds"]'),
('music', 'Music Player', 'YouTube- und Plex-Musik in Sprachkanälen. Queue-System, Loop, Lautstärke und mehr.', '1.0.0', 'media', '<path d="M8 0a8 8 0 1 0 0 16A8 8 0 0 0 8 0ZM4.5 7.5a.5.5 0 0 0 0 1h5.793l-2.147 2.146a.5.5 0 0 0 .708.708l3-3a.5.5 0 0 0 0-.708l-3-3a.5.5 1 0-.708.708L10.293 7.5H4.5Z"/>', 'BotHub', 1, 'music', '', '["bot_music_settings"]'),
('youtube_auth', 'YouTube Auth', 'Verbinde ein Google-Konto für authentifiziertes YouTube-Streaming. Ermöglicht altersgeschützte Inhalte.', '1.0.0', 'media', '<path d="M8 1C4.1 1 1 4.1 1 8s3.1 7 7 7 7-3.1 7-7-3.1-7-7-7zm3.1 9.9L7 8.6V4h1.5v3.8l3.5 2.1-.9 1z"/>', 'BotHub', 1, 'youtube-auth', 'CREATE TABLE IF NOT EXISTS system_youtube_token (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, access_token TEXT NOT NULL, refresh_token VARCHAR(2048) NOT NULL DEFAULT \'\', expires_at DATETIME NOT NULL, email VARCHAR(255) NOT NULL DEFAULT \'\', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', '["system_youtube_token"]'),
('pokemia', 'Pokemia', 'Pokémon-ähnliches Sammel- & Kampfsystem für deinen Discord-Server.', '1.0.0', 'fun', '<path d="M8 1a7 7 0 1 0 7 7A7 7 0 0 0 8 1Zm0 1.5A5.5 5.5 0 0 1 13.5 8H9.9a2 2 0 0 0-3.8 0H2.5A5.5 5.5 0 0 1 8 2.5ZM6.5 8a1.5 1.5 0 1 1 1.5 1.5A1.5 1.5 0 0 1 6.5 8Zm1.5 5.5A5.5 5.5 0 0 1 2.5 8h3.6a2 2 0 0 0 3.8 0h3.6A5.5 5.5 0 0 1 8 13.5Z"/>', 'BotHub', 1, 'pokemia', NULL, '["pokemia_users","pokemia_pokemon","pokemia_guild_config","pokemia_spawn"]'),
('ai', 'AI Chat', 'Verbinde deinen Bot mit KI-Diensten wie OpenAI, NVIDIA, Ollama und mehr.', '1.0.0', 'utility', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a1 1 0 0 1 1 1v1.07A8.001 8.001 0 0 1 20 12h-1a7 7 0 1 0-14 0H4a8.001 8.001 0 0 1 7-7.93V3a1 1 0 0 1 1-1ZM8 12a4 4 0 1 1 8 0 4 4 0 0 1-8 0Zm4-2a2 2 0 1 0 0 4 2 2 0 0 0 0-4Z"/><path d="M3.05 11H2a1 1 0 0 0 0 2h1.05A9.004 9.004 0 0 0 11 20.95V22a1 1 0 0 0 2 0v-1.05A9.004 9.004 0 0 0 20.95 13H22a1 1 0 0 0 0-2h-1.05A9.004 9.004 0 0 0 13 3.05V2a1 1 0 0 0-2 0v1.05A9.004 9.004 0 0 0 3.05 11Z"/></svg>', 'BotHub', 1, 'ai', NULL, '["bot_ai_settings","bot_ai_providers"]'),
('arcenciel', 'Arc en Ciel', 'KI-gestützte Bildgenerierung mit Stable Diffusion via Arc en Ciel API. Unterstützt /imagine, /img2img und /autotag.', '1.0.0', 'utility', '<path d="M8 1a7 7 0 1 0 0 14A7 7 0 0 0 8 1ZM2.4 8.8a5.6 5.6 0 1 1 11.2 0 5.6 5.6 0 0 1-11.2 0Zm5.6-2.4a.8.8 0 0 0-.8.8v1.6H5.6a.8.8 0 0 0 0 1.6h1.6v1.6a.8.8 0 0 0 1.6 0V10.4h1.6a.8.8 0 0 0 0-1.6H8.8V7.2a.8.8 0 0 0-.8-.8Z"/>', 'BotHub', 1, 'arcenciel', NULL, '["bot_arcenciel_settings"]')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

