-- database:  ${POSTFIXADMIN_DB_NAME}
--
-- Copyright 2026 William W. Kimball, Jr. MBA MSIS
--
-- Rollback refresh token rotation metadata (SEC-003)
DROP INDEX idx_rotated_from ON pfme_refresh_tokens;
DROP INDEX idx_last_used ON pfme_refresh_tokens;
DROP INDEX idx_family_id ON pfme_refresh_tokens;

ALTER TABLE pfme_refresh_tokens
    DROP COLUMN rotated_at
    , DROP COLUMN rotated_to
    , DROP COLUMN rotated_from
    , DROP COLUMN family_id
    , DROP COLUMN last_used_at
;
