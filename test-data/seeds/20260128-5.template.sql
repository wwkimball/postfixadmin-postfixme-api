-- database: ${POSTFIXADMIN_DB_NAME}
-- Seed file 20260128-5: Alias chains (aliases pointing to other aliases)

-- Create chained aliases (pointing to aliases defined in previous seed files)
-- These rely on aliases from 20260128-3 and 20260128-4
INSERT IGNORE INTO alias (address, goto, domain, active, created) VALUES
  ('helpdesk@acme.local', 'support@acme.local', 'acme.local', 1, NOW()),
  ('customerservice@acme.local', 'sales@acme.local', 'acme.local', 1, NOW()),
  ('webmaster@acme.local', 'info@acme.local', 'acme.local', 1, NOW()),
  ('helpdesk@zenith.local', 'support@zenith.local', 'zenith.local', 1, NOW()),
  ('customerservice@zenith.local', 'sales@zenith.local', 'zenith.local', 1, NOW());

