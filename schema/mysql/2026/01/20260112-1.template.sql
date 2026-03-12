-- database:  ${POSTFIXADMIN_DB_NAME}
--
-- Copyright 2026 William W. Kimball, Jr. MBA MSIS
--
-- Refresh tokens for JWT authentication
CREATE TABLE IF NOT EXISTS pfme_refresh_tokens (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    , token      VARCHAR(64) NOT NULL UNIQUE
    , mailbox    VARCHAR(255) NOT NULL
    , device_id  VARCHAR(255) DEFAULT NULL
    , expires_at DATETIME NOT NULL
    , created_at DATETIME NOT NULL
    , revoked_at DATETIME DEFAULT NULL
    , INDEX idx_mailbox (mailbox)
    , INDEX idx_token (token)
    , INDEX idx_expires (expires_at)
    , INDEX idx_device (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Revoked access tokens (JWT)
CREATE TABLE IF NOT EXISTS pfme_revoked_tokens (
    jti          VARCHAR(32) PRIMARY KEY
    , revoked_at DATETIME NOT NULL
    , INDEX idx_revoked_at (revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authentication audit log
CREATE TABLE IF NOT EXISTS pfme_auth_log (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
    , mailbox      VARCHAR(255) NOT NULL
    , success      BOOLEAN NOT NULL DEFAULT 0
    , ip_address   VARCHAR(45) DEFAULT NULL
    , user_agent   TEXT DEFAULT NULL
    , attempted_at DATETIME NOT NULL
    , INDEX idx_mailbox (mailbox)
    , INDEX idx_attempted (attempted_at)
    , INDEX idx_success (success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
