<?php
/**
 * PostfixMe API
 *
 * @package   PostfixMe API
 * @copyright Copyright (c) 2026 William Kimball, Jr., MBA, MSIS
 * @license   GPL-2.0-or-later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 */


namespace Pfme\Api\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\AliasService;
use Pfme\Api\Core\Database;

class AliasServiceCrudTest extends TestCase
{
    private AliasService $aliasService;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->aliasService = new AliasService();
        $this->db = Database::getConnection();

        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testListAliasesReturnsAuthorizedAndSorted(): void
    {
        $result = $this->aliasService->getAliasesForMailbox(
            'user1@acme.local',
            null,
            'address',
            'asc',
            1,
            20,
            null
        );

        $addresses = array_column($result['data'], 'address');
        $expected = [
            'all@acme.local',
            'contact@acme.local',
            'everyone@acme.local',
            'sales@acme.local',
            'team@acme.local',
        ];

        foreach ($expected as $alias) {
            $this->assertContains($alias, $addresses, "Expected {$alias} to be returned for user1");
        }

        $this->assertNotContains('support@acme.local', $addresses, 'Unauthorized aliases should not appear');
        $this->assertSame($addresses, array_values($addresses), 'Addresses should be a zero-indexed list');
        $this->assertSame($addresses, $this->sortedCopy($addresses), 'Addresses should be sorted ascending');
    }

    public function testListAliasesFiltersInactive(): void
    {
        $this->db->prepare('UPDATE alias SET active = 0 WHERE address = ?')->execute(['contact@acme.local']);

        $result = $this->aliasService->getAliasesForMailbox(
            'user1@acme.local',
            null,
            'address',
            'asc',
            1,
            20,
            'inactive'
        );

        $addresses = array_column($result['data'], 'address');
        $this->assertContains('contact@acme.local', $addresses, 'Inactive alias should be returned when filtered');
    }

    public function testCreateUpdateDeleteAliasWorkflow(): void
    {
        $localPart = 'qa-alias-' . uniqid();
        $aliasAddress = $localPart . '@acme.local';

        $created = $this->aliasService->createAlias(
            $localPart,
            'acme.local',
            ['user1@acme.local'],
            'user1@acme.local'
        );

        $this->assertEquals($aliasAddress, $created['address'] ?? null, 'Created alias should match requested address');
        $this->assertEquals(['user1@acme.local'], $created['destinations']);

        $updated = $this->aliasService->updateAlias(
            $aliasAddress,
            'user1@acme.local',
            [
                'destinations' => ['user1@acme.local', 'user3@acme.local'],
                'active' => false,
            ]
        );

        $this->assertEquals(['user1@acme.local', 'user3@acme.local'], $updated['destinations']);
        $this->assertFalse($updated['active']);

        $deleted = $this->aliasService->deleteAlias($aliasAddress, 'user1@acme.local');
        $this->assertTrue($deleted, 'Alias should be deleted after deactivation');

        $stmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM alias WHERE address = ?');
        $stmt->execute([$aliasAddress]);
        $this->assertEquals(0, (int)$stmt->fetchColumn(), 'Alias record should be removed from database');
    }

    public function testAvailableMailboxesFiltersByDomainAndQuery(): void
    {
        $result = $this->aliasService->getAvailableMailboxes('acme.local', 'user1');
        $emails = array_column($result, 'email');

        $this->assertContains('user1@acme.local', $emails, 'Should return matching mailbox for domain');
        $this->assertNotContains('user1@zenith.local', $emails, 'Should not leak other domains');
    }

    private function sortedCopy(array $values): array
    {
        $copy = $values;
        sort($copy, SORT_STRING);
        return $copy;
    }
}
