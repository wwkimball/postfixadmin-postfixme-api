-- database: ${POSTFIXADMIN_DB_NAME}
-- Rollback for seed file 20260128-0: Remove seed data version tracker
DELETE FROM ${DBSCHEMA_SETTINGS_TABLE}
	WHERE name IN ('seed_version');
