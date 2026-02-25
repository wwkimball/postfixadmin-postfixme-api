-- database: ${POSTFIXADMIN_DB_NAME}
-- Rollback for seed file 20260128-2: Remove test mailboxes

DELETE FROM mailbox WHERE username IN (
  'user1@acme.local', 'user2@acme.local', 'user3@acme.local', 'user4@acme.local',
  'user1@zenith.local', 'user2@zenith.local'
);
