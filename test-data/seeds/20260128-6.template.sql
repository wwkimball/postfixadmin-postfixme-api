-- database: ${POSTFIXADMIN_DB_NAME}
-- Seed file 20260128-6: Disqualifying aliases (external/out-of-domain addresses)

-- External aliases that forward to addresses outside the domain
INSERT IGNORE INTO alias (address, goto, domain, active, created) VALUES
  ('external@acme.local', 'external@example.com', 'acme.local', 1, NOW()),
  ('vendor@acme.local', 'vendor@thirdparty.io', 'acme.local', 1, NOW()),
  ('partner@acme.local', 'partner@partners.com', 'acme.local', 1, NOW()),
  ('outsourced@acme.local', 'contractor@outsource.biz', 'acme.local', 1, NOW()),
  ('thirdparty@acme.local', 'support@thirdparty.co', 'acme.local', 1, NOW()),
  ('external@zenith.local', 'contact@external.com', 'zenith.local', 1, NOW()),
  ('vendor@zenith.local', 'sales@vendor.io', 'zenith.local', 1, NOW());
