-- database:  ${POSTFIXADMIN_DB_NAME}
--
-- Copyright 2026 William W. Kimball, Jr. MBA MSIS
--
-- Refresh token rotation metadata (SEC-003)
ALTER TABLE pfme_refresh_tokens
    ADD COLUMN IF NOT EXISTS last_used_at DATETIME DEFAULT NULL AFTER created_at
    , ADD COLUMN IF NOT EXISTS family_id VARCHAR(64) DEFAULT NULL AFTER revoked_at
    , ADD COLUMN IF NOT EXISTS rotated_from VARCHAR(64) DEFAULT NULL AFTER family_id
    , ADD COLUMN IF NOT EXISTS rotated_to VARCHAR(64) DEFAULT NULL AFTER rotated_from
    , ADD COLUMN IF NOT EXISTS rotated_at DATETIME DEFAULT NULL AFTER rotated_to
;

CREATE INDEX IF NOT EXISTS idx_family_id ON pfme_refresh_tokens (family_id);
CREATE INDEX IF NOT EXISTS idx_last_used ON pfme_refresh_tokens (last_used_at);
CREATE INDEX IF NOT EXISTS idx_rotated_from ON pfme_refresh_tokens (rotated_from);
