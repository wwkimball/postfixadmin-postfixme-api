-- database: ${POSTFIXADMIN_DB_NAME}
-- Rollback for seed file 20260128-6: Remove disqualifying aliases

DELETE FROM alias WHERE
  (domain = 'acme.local' AND address IN ('external@acme.local', 'vendor@acme.local', 'partner@acme.local', 'outsourced@acme.local', 'thirdparty@acme.local')) OR
  (domain = 'zenith.local' AND address IN ('external@zenith.local', 'vendor@zenith.local'));
