# Test Seed Data for Development, Lab, and QA

This directory contains test data seed files that are applied idempotently at
container boot in development, lab, and qa deployment stages only.  These files
are **not to be included** in staging or production images.

## Purpose

Provides a consistent, comprehensive test dataset enabling:

- Manual testing with known accounts
- Automated testing against predictable data
- Reproducible issue investigation across teams
- Validation of all alias mapping scenarios

## Test Domains

- **acme.local** - Primary test domain
- **zenith.local** - Secondary test domain for cross-domain testing

## Test Mailbox Credentials

All passwords are hashed using Dovecot SHA512-CRYPT, one of the supported PostfixAdmin encryption schemes.

| Email Address | Plaintext Password | Purpose |
| ------------- | ------------------ | ------- |
| admin\@acme.local | testpass123 | Domain administrator |
| user1\@acme.local | testpass123 | Basic mailbox, 1:1 alias target |
| user2\@acme.local | testpass123 | M:N alias target |
| user3\@acme.local | testpass123 | M:N alias target |
| admin\@zenith.local | testpass123 | Cross-domain administrator |
| user4\@zenith.local | testpass123 | Cross-domain mailbox |

## PostfixAdmin Admin Console Credentials

PostfixAdmin admin accounts have full administrative access to their designated domain(s).

| Email Address | Plaintext Password | Domains |
| ------------- | ------------------ | ------- |
| admin\@acme.local | testpass123 | acme.local |
| admin\@zenith.local | testpass123 | zenith.local |

### Logging Into PostfixAdmin as a Test Administrator

```text
URL: http://localhost:8080
Username: admin@acme.local  (or admin@zenith.local)
Password: testpass123
```

**Note:** Admin credentials are stored in three tables:

- `admin` - Contains the admin username and password hash
- `domain_admins` - Links admins to their domain(s)
- `mailbox` - Mailbox entry for the admin (enables API access)

## Test Alias Scenarios

### 1:1 Alias Mappings

- `contact@acme.local` -> `user1@acme.local`
- `info@zenith.local` -> `user4@zenith.local`

### M:1 Alias Mappings (Multiple aliases to one mailbox)

- `sales@acme.local` -> `admin@acme.local`
- `support@acme.local` -> `admin@acme.local`

### M:N Alias Mappings (One alias to multiple mailboxes)

- `team@acme.local` -> `user1@acme.local, user2@acme.local, user3@acme.local`
- `all@zenith.local` -> `admin@zenith.local, user4@zenith.local`

### Alias Chains (alias -> alias -> mailbox)

- `helpdesk@acme.local` -> `support@acme.local` -> `admin@acme.local`

### Disqualifying Aliases (Out-of-domain destinations - users cannot self-manage)

- `external@acme.local` -> `someone@external.com`
- `outsourced@zenith.local` -> `contractor@partner.net`

## Seed File Execution

Seed files are processed by `/opt/lib/database/schema/mysql.sh` at container
boot only for development, lab, and qa deployment stages:

- Executed in alpha-numerical order by filename
- Version tracked in settings table using `seed_version` key
- Idempotent - safe to re-run without data duplication
- Rollback files support downgrade operations

## Adding New Test Data

Use the provided helper scripts to generate properly-hashed INSERT statements:

```bash
# Add a new mailbox
./compose.sh exec postfixadmin add-test-mailbox.sh \
  user5@acme.local "NewPassword123"

# Add a new alias
./compose.sh exec postfixadmin add-test-alias.sh \
  devs@acme.local "user1@acme.local,user2@acme.local"
```

Scripts output SQL ready to append to new seed files.

## QA Test Data Management

### Resetting to Clean State

To purge all test data and reload from seed files:

```bash
# Remove all test domain data and version tracking
./compose.sh exec postfixadmin purge-test-data.sh

# Reload all seed files (must run after purge)
./compose.sh exec postfixadmin reload-test-data.sh
```

This two-step process ensures a clean slate for QA testing:

1. **purge-test-data.sh** - Deletes all data for acme.local and zenith.local domains
2. **reload-test-data.sh** - Applies all seed files from `/opt/postfixadmin/seeds/`

## Security Notice

**These credentials are for testing purposes only.**  Never use these accounts,
passwords, or domains in staging or production environments.
