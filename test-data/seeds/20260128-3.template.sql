-- database: ${POSTFIXADMIN_DB_NAME}
-- Seed file 20260128-3: 1:1 alias mappings

INSERT IGNORE INTO alias (address, goto, domain, active, created) VALUES
  ('contact@acme.local', 'user1@acme.local', 'acme.local', 1, NOW()),
  ('postmaster@acme.local', 'admin@acme.local', 'acme.local', 1, NOW()),
  ('info@acme.local', 'user2@acme.local', 'acme.local', 1, NOW()),
  ('abuse@acme.local', 'admin@acme.local', 'acme.local', 1, NOW()),
  ('contact@zenith.local', 'user1@zenith.local', 'zenith.local', 1, NOW()),
  ('postmaster@zenith.local', 'admin@zenith.local', 'zenith.local', 1, NOW()),
  ('info@zenith.local', 'user2@zenith.local', 'zenith.local', 1, NOW()),
  ('abuse@zenith.local', 'admin@zenith.local', 'zenith.local', 1, NOW());
