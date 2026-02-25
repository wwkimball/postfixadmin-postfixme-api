-- database:  ${POSTFIXADMIN_DB_NAME}
--
-- Copyright 2026 William W. Kimball, Jr. MBA MSIS
--
-- Remove JWT authentication and audit logging tables
DROP TABLE IF EXISTS pfme_auth_log;
DROP TABLE IF EXISTS pfme_revoked_tokens;
DROP TABLE IF EXISTS pfme_refresh_tokens;
