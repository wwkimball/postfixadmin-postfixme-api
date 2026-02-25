-- database: ${POSTFIXADMIN_DB_NAME}
-- Rollback for seed file 20260128-3: Remove 1:1 aliases

DELETE FROM alias WHERE
  (domain = 'acme.local' AND address IN ('contact@acme.local', 'postmaster@acme.local', 'info@acme.local', 'abuse@acme.local')) OR
  (domain = 'zenith.local' AND address IN ('contact@zenith.local', 'postmaster@zenith.local', 'info@zenith.local', 'abuse@zenith.local'));
