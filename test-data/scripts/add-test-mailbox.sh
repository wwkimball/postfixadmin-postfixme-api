#!/usr/bin/env bash
################################################################################
# Generate SQL INSERT statement for a new test mailbox with bcrypt password.
#
# This script creates a properly-hashed mailbox INSERT statement ready to
# append to a new seed file. The password is hashed using PostfixAdmin's
# BLF-CRYPT (bcrypt) scheme via doveadm pw.
#
# Usage:
#   add-test-mailbox.sh <email> <password> [quota_bytes]
#
# Example:
#   add-test-mailbox.sh user5@acme.local "SecurePass123" 1073741824
#
# Output is SQL ready to paste into a new seed file (e.g., 20260128-7.sql).
#
# Copyright 2026 William W. Kimball, Jr., MBA, MSIS
################################################################################

set -euo pipefail

# Validate arguments
if [ $# -lt 2 ]; then
    echo "Usage: $0 <email> <password> [quota_bytes]" >&2
    echo "" >&2
    echo "Example: $0 user5@acme.local 'SecurePass123' 1073741824" >&2
    exit 1
fi

EMAIL="$1"
PASSWORD="$2"
QUOTA="${3:-1073741824}"  # Default 1GB

# Validate email format
if [[ ! "$EMAIL" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
    echo "ERROR: Invalid email format: $EMAIL" >&2
    exit 1
fi

# Extract local_part and domain
LOCAL_PART="${EMAIL%%@*}"
DOMAIN="${EMAIL##*@}"

# Generate maildir path
MAILDIR="${DOMAIN}/${LOCAL_PART}/"

# Generate bcrypt password hash using doveadm
if ! command -v doveadm &> /dev/null; then
    echo "ERROR: doveadm command not found. Install dovecot-core package." >&2
    exit 1
fi

# Hash the password with BLF-CRYPT (bcrypt)
PASSWORD_HASH=$(doveadm pw -s BLF-CRYPT -p "$PASSWORD" | cut -d'}' -f2)

# Generate name from local_part (capitalize first letter of each word)
NAME=$(echo "$LOCAL_PART" | sed 's/_/ /g' | sed 's/\b\(.\)/\u\1/g')

# Output SQL INSERT statement
cat <<EOSQL
-- Add test mailbox: $EMAIL (password: $PASSWORD)
INSERT IGNORE INTO mailbox (username, password, name, maildir, quota, local_part, domain, active, created, modified)
VALUES
('$EMAIL', '$PASSWORD_HASH', 'Test $NAME', '$MAILDIR', $QUOTA, '$LOCAL_PART', '$DOMAIN', 1, NOW(), NOW());
EOSQL

echo "" >&2
echo "Generated INSERT statement for $EMAIL" >&2
echo "  Password: $PASSWORD" >&2
echo "  Hash: $PASSWORD_HASH" >&2
echo "" >&2
echo "Append this SQL to a new seed file and create a matching rollback file." >&2
