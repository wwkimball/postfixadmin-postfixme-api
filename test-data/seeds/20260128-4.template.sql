-- database: ${POSTFIXADMIN_DB_NAME}
-- Seed file 20260128-4: Many-to-one and many-to-many alias mappings

-- M:1 aliases (many mailboxes to one recipient)
INSERT IGNORE INTO alias (address, goto, domain, active, created) VALUES
  ('sales@acme.local', 'user1@acme.local', 'acme.local', 1, NOW()),
  ('support@acme.local', 'user2@acme.local', 'acme.local', 1, NOW()),
  ('billing@acme.local', 'user3@acme.local', 'acme.local', 1, NOW());

-- M:N aliases (forward to multiple recipients)
INSERT IGNORE INTO alias (address, goto, domain, active, created) VALUES
  ('team@acme.local', 'user1@acme.local,user2@acme.local,user3@acme.local', 'acme.local', 1, NOW()),
  ('everyone@acme.local', 'user1@acme.local,user2@acme.local,user3@acme.local,user4@acme.local', 'acme.local', 1, NOW()),
  ('all@acme.local', 'user1@acme.local,user2@acme.local,user3@acme.local,user4@acme.local', 'acme.local', 1, NOW());

-- Similar for zenith.local
INSERT IGNORE INTO alias (address, goto, domain, active, created) VALUES
  ('sales@zenith.local', 'user1@zenith.local', 'zenith.local', 1, NOW()),
  ('support@zenith.local', 'user2@zenith.local', 'zenith.local', 1, NOW()),
  ('team@zenith.local', 'user1@zenith.local,user2@zenith.local', 'zenith.local', 1, NOW());
