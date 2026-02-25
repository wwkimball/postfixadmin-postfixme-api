-- database: ${POSTFIXADMIN_DB_NAME}
-- Rollback for seed file 20260128-5: Remove alias chains

DELETE FROM alias WHERE
  (domain = 'acme.local' AND address IN ('helpdesk@acme.local', 'customerservice@acme.local', 'webmaster@acme.local', 'support@acme.local', 'sales@acme.local', 'info@acme.local')) OR
  (domain = 'zenith.local' AND address IN ('helpdesk@zenith.local', 'support@zenith.local'));
