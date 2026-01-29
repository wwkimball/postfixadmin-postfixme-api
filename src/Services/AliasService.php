<?php

namespace Pfme\Api\Services;

use Pfme\Api\Core\Database;

/**
 * Alias Service - manages email aliases scoped to authenticated user
 */
class AliasService
{
    public function getAliasesForMailbox(
        string $mailbox,
        ?string $query = null,
        ?string $status = null,
        int $page = 1,
        int $perPage = 20,
        string $sort = 'address'
    ): array {
        $db = Database::getConnection();

        // Build WHERE clause
        $where = ['a.goto LIKE ?'];
        $params = ["%{$mailbox}%"];

        if ($query) {
            $where[] = 'a.address LIKE ?';
            $params[] = "%{$query}%";
        }

        if ($status === 'active') {
            $where[] = 'a.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'a.active = 0';
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM alias a WHERE {$whereClause}";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetch()['total'];

        // Get paginated results
        $offset = ($page - 1) * $perPage;

        $orderBy = $this->getSortColumn($sort);
        $sql = "SELECT * FROM alias a WHERE {$whereClause} ORDER BY {$orderBy} LIMIT ? OFFSET ?";

        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $aliases = $stmt->fetchAll();

        // Transform results
        $result = array_map(function ($alias) use ($mailbox) {
            return $this->transformAlias($alias, $mailbox);
        }, $aliases);

        return [
            'data' => $result,
            'total' => $total,
        ];
    }

    public function getAliasById(string $address, string $mailbox): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT * FROM alias WHERE address = ? AND goto LIKE ?');
        $stmt->execute([$address, "%{$mailbox}%"]);

        $alias = $stmt->fetch();

        if (!$alias) {
            return null;
        }

        return $this->transformAlias($alias, $mailbox);
    }

    public function createAlias(string $localPart, string $domain, array $destinations, string $mailbox): array
    {
        // Validate that user's mailbox is in destinations
        if (!in_array($mailbox, $destinations)) {
            throw new \Exception('Your mailbox must be included in alias destinations');
        }

        $address = $localPart . '@' . $domain;

        // Check if alias already exists
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT 1 FROM alias WHERE address = ?');
        $stmt->execute([$address]);

        if ($stmt->fetch()) {
            throw new \Exception('Alias already exists');
        }

        // Validate destinations against domain policy
        $this->validateDestinations($destinations, $domain);

        // Create alias
        $goto = implode(',', $destinations);
        $stmt = $db->prepare(
            'INSERT INTO alias (address, goto, domain, active, created)
             VALUES (?, ?, ?, 1, NOW())'
        );

        $stmt->execute([$address, $goto, $domain]);

        return $this->getAliasById($address, $mailbox);
    }

    public function updateAlias(string $address, string $mailbox, array $updates): ?array
    {
        $alias = $this->getAliasById($address, $mailbox);

        if (!$alias) {
            return null;
        }

        $db = Database::getConnection();
        $setParts = [];
        $params = [];

        $targetAddress = $address;

        // Handle local_part rename
        if (isset($updates['local_part'])) {
            $newAddress = $updates['local_part'] . '@' . $alias['domain'];

            // Check if new address already exists
            $stmt = $db->prepare('SELECT 1 FROM alias WHERE address = ? AND address != ?');
            $stmt->execute([$newAddress, $address]);

            if ($stmt->fetch()) {
                throw new \Exception('An alias with that name already exists');
            }

            $setParts[] = 'address = ?';
            $params[] = $newAddress;
            $targetAddress = $newAddress;
        }

        // Handle destinations update
        if (isset($updates['destinations'])) {
            if (!in_array($mailbox, $updates['destinations'])) {
                throw new \Exception('Your mailbox must be included in alias destinations');
            }

            $this->validateDestinations($updates['destinations'], $alias['domain']);

            $setParts[] = 'goto = ?';
            $params[] = implode(',', $updates['destinations']);
        }

        // Handle active status
        if (isset($updates['active'])) {
            $setParts[] = 'active = ?';
            $params[] = $updates['active'] ? 1 : 0;
        }

        if (empty($setParts)) {
            return $alias; // No changes
        }

        $setParts[] = 'modified = NOW()';

        $sql = 'UPDATE alias SET ' . implode(', ', $setParts) . ' WHERE address = ?';
        $params[] = $address;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $this->getAliasById($targetAddress, $mailbox);
    }

    public function deleteAlias(string $address, string $mailbox): bool
    {
        $alias = $this->getAliasById($address, $mailbox);

        if (!$alias) {
            return false;
        }

        // Enforce: alias must be inactive before deletion
        if ($alias['active']) {
            throw new \Exception('Alias must be disabled before deletion');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM alias WHERE address = ?');
        $stmt->execute([$address]);

        return true;
    }

    private function transformAlias(array $alias, string $userMailbox): array
    {
        list($localPart, $domain) = explode('@', $alias['address'], 2);

        $destinations = array_filter(array_map('trim', explode(',', $alias['goto'])));

        return [
            'id' => $alias['address'],  // Use address as unique identifier
            'local_part' => $localPart,
            'domain' => $domain,
            'address' => $alias['address'],
            'destinations' => $destinations,
            'active' => (bool)$alias['active'],
            'created' => $alias['created'] ?? null,
            'modified' => $alias['modified'] ?? null,
        ];
    }

    private function validateDestinations(array $destinations, string $domain): void
    {
        // Basic validation: all destinations should be valid email addresses
        foreach ($destinations as $dest) {
            if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception("Invalid destination email: {$dest}");
            }
        }

        // Additional policy checks could be added here
        // For example: checking against domain's allowed destination patterns
    }

    private function getSortColumn(string $sort): string
    {
        $validSorts = [
            'address' => 'a.address ASC',
            'created' => 'a.created DESC',
            'modified' => 'a.modified DESC',
        ];

        return $validSorts[$sort] ?? 'a.address ASC';
    }

    public function getAvailableMailboxes(string $domain, string $query = null): array
    {
        $db = Database::getConnection();

        $sql = 'SELECT username, name FROM mailbox WHERE domain = ? AND active = 1';
        $params = [$domain];

        if ($query) {
            $sql .= ' AND username LIKE ?';
            $params[] = "%{$query}%";
        }

        $sql .= ' ORDER BY username LIMIT 50';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return array_map(function ($row) {
            return [
                'email' => $row['username'],
                'name' => $row['name'] ?? null,
            ];
        }, $stmt->fetchAll());
    }
}
