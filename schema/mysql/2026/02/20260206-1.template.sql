-- database:  ${POSTFIXADMIN_DB_NAME}
--
-- Copyright 2026 William W. Kimball, Jr. MBA MSIS
--
-- Track password change timestamps to invalidate access tokens
CREATE TABLE IF NOT EXISTS pfme_mailbox_security (
    mailbox               VARCHAR(255) PRIMARY KEY
    , password_changed_at DATETIME NOT NULL
    , updated_at          DATETIME NOT NULL
    , INDEX idx_password_changed (password_changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
