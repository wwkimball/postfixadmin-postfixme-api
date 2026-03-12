-- database:  ${POSTFIXADMIN_DB_NAME}
--
-- Copyright 2026 William W. Kimball, Jr. MBA MSIS
--
-- PostfixMe Database Schema Rollback - Restore device_id column
ALTER TABLE pfme_refresh_tokens ADD COLUMN device_id VARCHAR(255) DEFAULT NULL AFTER mailbox;
ALTER TABLE pfme_refresh_tokens ADD INDEX idx_device (device_id);
