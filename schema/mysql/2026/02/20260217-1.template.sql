-- database:  ${POSTFIXADMIN_DB_NAME}
--
-- Copyright 2026 William W. Kimball, Jr. MBA MSIS
--
-- PostfixMe Database Schema - Remove device_id column
-- The device_id column was created for device tracking functionality (SEC-006)
-- with the intention of enabling users to view and revoke active sessions on
-- specific devices.  However, this feature was never fully implemented due to
-- possible conflict with privacy concerns, and so was never actually used.  It
-- has been removed from both the iOS app and the API.
ALTER TABLE pfme_refresh_tokens DROP INDEX idx_device;
ALTER TABLE pfme_refresh_tokens DROP COLUMN device_id;
