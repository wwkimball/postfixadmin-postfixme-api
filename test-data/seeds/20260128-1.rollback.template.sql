-- database: ${POSTFIXADMIN_DB_NAME}
-- Rollback for seed file 20260128-1: Remove domains and admin accounts

DELETE FROM domain_admins WHERE username IN ('admin@acme.local', 'admin@zenith.local');

DELETE FROM admin WHERE username IN ('admin@acme.local', 'admin@zenith.local');

DELETE FROM domain WHERE domain IN ('acme.local', 'zenith.local');

