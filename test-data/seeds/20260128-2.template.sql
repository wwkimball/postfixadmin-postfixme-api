-- database: ${POSTFIXADMIN_DB_NAME}
-- Seed file 20260128-2: Test mailboxes

-- Test mailboxes in acme.local (password: testpass123)
INSERT IGNORE INTO mailbox (username, password, name, quota, domain, local_part, active, created) VALUES
  ('user1@acme.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Test User 1', 5368709120, 'acme.local', 'user1', 1, NOW()),
  ('user2@acme.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Test User 2', 5368709120, 'acme.local', 'user2', 1, NOW()),
  ('user3@acme.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Test User 3', 5368709120, 'acme.local', 'user3', 1, NOW()),
  ('user4@acme.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Test User 4', 5368709120, 'acme.local', 'user4', 1, NOW());

-- Test mailboxes in zenith.local (password: testpass123)
INSERT IGNORE INTO mailbox (username, password, name, quota, domain, local_part, active, created) VALUES
  ('user1@zenith.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Test User 1', 5368709120, 'zenith.local', 'user1', 1, NOW()),
  ('user2@zenith.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Test User 2', 5368709120, 'zenith.local', 'user2', 1, NOW()),
  ('user4@zenith.local', '{SHA512-CRYPT}$6$BTNRDHG7gSERN.jG$W127iKLHhSfW0ahdRfcSCynGfNALsONmkWhSqp.kftdAVGEaS0vWp/vD9XCLeUdIs1GLrs6SAzWMF2/z2KeXy1', 'Test User 4', 5368709120, 'zenith.local', 'user4', 1, NOW());
