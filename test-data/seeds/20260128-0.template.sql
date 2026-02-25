-- database: ${POSTFIXADMIN_DB_NAME}
-- Seed file 20260128-0: Seed data version tracker
-- Initialize seed_version tracker
INSERT IGNORE INTO ${DBSCHEMA_SETTINGS_TABLE} (name, value) VALUES ('seed_version', '20260128-0');
