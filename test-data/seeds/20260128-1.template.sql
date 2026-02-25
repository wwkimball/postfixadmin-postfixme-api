-- database: ${POSTFIXADMIN_DB_NAME}
-- Seed file 20260128-1: Domains and admin accounts

-- Create test domains
INSERT IGNORE INTO domain (domain, description, aliases, mailboxes, active, created)
VALUES
  ('acme.local', 'ACME Corp test domain', 10, 5, 1, NOW()),
  ('zenith.local', 'Zenith test domain', 10, 5, 1, NOW());

-- Create domain admin accounts (password: testpass123)
INSERT IGNORE INTO admin (username, password, active, created) VALUES
  ('admin@acme.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 1, NOW()),
  ('admin@zenith.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 1, NOW());

-- Link admins to their domains
INSERT IGNORE INTO domain_admins (username, domain, active, created) VALUES
  ('admin@acme.local', 'acme.local', 1, NOW()),
  ('admin@zenith.local', 'zenith.local', 1, NOW());

-- Create admin mailboxes for API access
INSERT IGNORE INTO mailbox (username, password, name, quota, domain, local_part, active, created) VALUES
  ('admin@acme.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Admin', 5368709120, 'acme.local', 'admin', 1, NOW()),
  ('admin@zenith.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Admin', 5368709120, 'zenith.local', 'admin', 1, NOW());

