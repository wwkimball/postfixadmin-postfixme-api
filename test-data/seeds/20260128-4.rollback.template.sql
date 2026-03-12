-- database: ${POSTFIXADMIN_DB_NAME}
-- Rollback for seed file 20260128-4: Remove M:1 and M:N aliases

DELETE FROM alias WHERE
  (domain = 'acme.local' AND address IN ('sales@acme.local', 'support@acme.local', 'billing@acme.local', 'team@acme.local', 'everyone@acme.local', 'all@acme.local')) OR
  (domain = 'zenith.local' AND address IN ('sales@zenith.local', 'support@zenith.local', 'team@zenith.local'));
