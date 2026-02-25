-- database:  ${POSTFIXADMIN_DB_NAME}
--
-- Copyright 2026 William W. Kimball, Jr. MBA MSIS
--
-- PostfixMe Database Schema Rollback
CREATE TABLE IF NOT EXISTS pfme_auth_log_summary (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    , mailbox             VARCHAR(255) NOT NULL
    , summary_date        DATE NOT NULL
    , failed_attempts     INT UNSIGNED NOT NULL DEFAULT 0
    , successful_attempts INT UNSIGNED NOT NULL DEFAULT 0
    , created_at          DATETIME NOT NULL
    , updated_at          DATETIME NOT NULL
    , UNIQUE KEY uniq_mailbox_date (mailbox, summary_date)
    , INDEX idx_summary_date (summary_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pfme_auth_log_archive (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    , mailbox      VARCHAR(255) NOT NULL
    , success      BOOLEAN NOT NULL DEFAULT 0
    , ip_address   VARCHAR(45) DEFAULT NULL
    , user_agent   TEXT DEFAULT NULL
    , attempted_at DATETIME NOT NULL
    , archived_at  DATETIME NOT NULL
    , INDEX idx_mailbox (mailbox)
    , INDEX idx_attempted (attempted_at)
    , INDEX idx_success (success)
    , INDEX idx_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
