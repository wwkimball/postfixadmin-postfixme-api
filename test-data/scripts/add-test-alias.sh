#!/usr/bin/env bash
################################################################################
# Generate SQL INSERT statement for a new test alias.
#
# This script creates an alias INSERT statement ready to append to a new seed
# file. Supports 1:1, M:1, and M:N alias mappings.
#
# Usage:
#   add-test-alias.sh <alias_email> <destination_emails>
#
# Where destination_emails is either:
#   - Single email: user1@acme.local
#   - Multiple emails (comma-separated): user1@acme.local,user2@acme.local
#
# Examples:
#   # 1:1 alias
#   add-test-alias.sh hello@acme.local user1@acme.local
#
#   # M:N alias (one alias to many mailboxes)
#   add-test-alias.sh devteam@acme.local "user1@acme.local,user2@acme.local,user3@acme.local"
#
# Output is SQL ready to paste into a new seed file (e.g., 20260128-7.sql).
#
# Copyright 2026 William W. Kimball, Jr., MBA, MSIS
################################################################################
set -euo pipefail

# Validate arguments
if [ $# -ne 2 ]; then
    echo "Usage: $0 <alias_email> <destination_emails>" >&2
    echo "" >&2
    echo "Examples:" >&2
    echo "  $0 hello@acme.local user1@acme.local" >&2
    echo "  $0 devteam@acme.local 'user1@acme.local,user2@acme.local'" >&2
    exit 1
fi

ALIAS_EMAIL="$1"
DESTINATIONS="$2"

# Validate alias email format
if [[ ! "$ALIAS_EMAIL" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
    echo "ERROR: Invalid alias email format: $ALIAS_EMAIL" >&2
    exit 1
fi

# Extract domain from alias
DOMAIN="${ALIAS_EMAIL##*@}"

# Validate each destination email
IFS=',' read -ra DEST_ARRAY <<< "$DESTINATIONS"
for dest in "${DEST_ARRAY[@]}"; do
    # Trim whitespace
    dest=$(echo "$dest" | xargs)
    if [[ ! "$dest" =~ ^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$ ]]; then
        echo "ERROR: Invalid destination email format: $dest" >&2
        exit 1
    fi
done

# Count destinations to determine alias type
DEST_COUNT=${#DEST_ARRAY[@]}
if [ $DEST_COUNT -eq 1 ]; then
    ALIAS_TYPE="1:1"
else
    ALIAS_TYPE="M:N (1 alias → $DEST_COUNT mailboxes)"
fi

# Output SQL INSERT statement
cat <<EOSQL
-- Add test alias: $ALIAS_EMAIL → $DESTINATIONS ($ALIAS_TYPE)
INSERT INTO alias (address, goto, domain, active, created, modified)
VALUES
('$ALIAS_EMAIL', '$DESTINATIONS', '$DOMAIN', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  goto = VALUES(goto),
  active = VALUES(active),
  modified = NOW();
EOSQL

echo "" >&2
echo "Generated INSERT statement for $ALIAS_TYPE alias" >&2
echo "  Alias: $ALIAS_EMAIL" >&2
echo "  Destination(s): $DESTINATIONS" >&2
echo "" >&2
echo "Append this SQL to a new seed file and create a matching rollback file." >&2
