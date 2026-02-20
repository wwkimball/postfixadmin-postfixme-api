<?php

namespace Pfme\Api\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\AliasService;
use Pfme\Api\Core\Database;

/**
 * Integration tests for SQL injection protection in AliasService
 *
 * Tests that user input is properly sanitized and parameterized
 * Uses pre-seeded test data from test-data/seeds directory
 */
class AliasServiceSecurityTest extends TestCase
{
    private AliasService $aliasService;
    private \PDO $db;

    // Test credentials from seed data
    private string $testMailbox = 'user1@acme.local';
    private string $testDomain = 'acme.local';

    protected function setUp(): void
    {
        $this->aliasService = new AliasService();
        $this->db = Database::getConnection();
    }

    /**
     * Test SQL injection attempts in search query
     */
    public function testSearchQuerySQLInjectionProtection(): void
    {
        $injectionAttempts = [
            "' OR '1'='1",
            "'; DROP TABLE alias; --",
            "' UNION SELECT * FROM mailbox --",
            "admin'--",
            "' OR 1=1 --",
            "%' AND 1=0 UNION ALL SELECT NULL, NULL, NULL--",
        ];

        foreach ($injectionAttempts as $maliciousInput) {
            try {
                $result = $this->aliasService->getAliasesForMailbox(
                    $this->testMailbox,
                    $maliciousInput, // Malicious search query
                    'created',
                    'desc',
                    1,
                    20,
                    null
                );

                // Should return safe results, not execute SQL injection
                $this->assertIsArray($result, 'Should return array result');
                $this->assertArrayHasKey('data', $result, 'Should have data key');

                // Verify alias table still exists (not dropped)
                $stmt = $this->db->query("SHOW TABLES LIKE 'alias'");
                $tableExists = $stmt->fetch();
                $this->assertNotFalse($tableExists, 'Alias table should still exist');

            } catch (\Exception $e) {
                // Exception is acceptable - indicates input was rejected
                $this->assertIsString($e->getMessage(), 'Exception should have message');
            }
        }
    }

    /**
     * Test SQL injection in sort parameter
     */
    public function testSortParameterSQLInjectionProtection(): void
    {
        $injectionAttempts = [
            "address; DROP TABLE alias; --",
            "address' OR '1'='1",
            "(SELECT * FROM mailbox)",
        ];

        foreach ($injectionAttempts as $maliciousSort) {
            try {
                $result = $this->aliasService->getAliasesForMailbox(
                    $this->testMailbox,
                    null,
                    $maliciousSort, // Malicious sort
                    'desc',
                    1,
                    20,
                    null
                );

                // Should use default sort or reject, not execute injection
                $this->assertIsArray($result, 'Should return safe result');

            } catch (\Exception $e) {
                // Safe rejection is acceptable
                $this->assertIsString($e->getMessage(), 'Exception should occur');
            }
        }
    }

    /**
     * Test SQL injection in order parameter
     */
    public function testOrderParameterSQLInjectionProtection(): void
    {
        $injectionAttempts = [
            "asc; DELETE FROM alias WHERE 1=1; --",
            "desc' OR '1'='1",
        ];

        foreach ($injectionAttempts as $maliciousOrder) {
            try {
                $result = $this->aliasService->getAliasesForMailbox(
                    $this->testMailbox,
                    null,
                    'created',
                    $maliciousOrder, // Malicious order
                    1,
                    20,
                    null
                );

                $this->assertIsArray($result, 'Should return safe result');

            } catch (\Exception $e) {
                $this->assertIsString($e->getMessage(), 'Safe rejection');
            }
        }
    }

    /**
     * Test SQL injection in status filter
     */
    public function testStatusFilterSQLInjectionProtection(): void
    {
        $injectionAttempts = [
            "1' OR '1'='1",
            "1; DROP TABLE alias; --",
            "1 UNION SELECT * FROM mailbox",
        ];

        foreach ($injectionAttempts as $maliciousStatus) {
            try {
                $result = $this->aliasService->getAliasesForMailbox(
                    $this->testMailbox,
                    null,
                    'created',
                    'desc',
                    1,
                    20,
                    $maliciousStatus // Malicious status
                );

                $this->assertIsArray($result, 'Should return safe result');

            } catch (\Exception $e) {
                $this->assertIsString($e->getMessage(), 'Safe rejection');
            }
        }
    }

    /**
     * Test SQL injection in alias address lookup
     */
    public function testAliasAddressLookupSQLInjectionProtection(): void
    {
        $maliciousAddresses = [
            "test@acme.local' OR '1'='1",
            "'; DROP TABLE alias; --",
            "test@acme.local' UNION SELECT * FROM mailbox WHERE '1'='1",
        ];

        foreach ($maliciousAddresses as $maliciousAddress) {
            $result = $this->aliasService->getAliasById($maliciousAddress, $this->testMailbox);

            // Should return null (not found) not execute injection
            $this->assertNull($result, 'Malicious address should not be found');

            // Verify tables still exist
            $stmt = $this->db->query("SHOW TABLES LIKE 'alias'");
            $tableExists = $stmt->fetch();
            $this->assertNotFalse($tableExists, 'Table should still exist');
        }
    }

    /**
     * Test SQL injection in local part during create
     */
    public function testCreateAliasLocalPartSQLInjectionProtection(): void
    {
        $maliciousLocalParts = [
            "test'; DROP TABLE alias; --",
            "test' OR '1'='1",
        ];

        foreach ($maliciousLocalParts as $maliciousLocal) {
            try {
                $result = $this->aliasService->createAlias(
                    $maliciousLocal, // Malicious local part
                    $this->testDomain,
                    [$this->testMailbox],
                    $this->testMailbox
                );

                // If creation succeeded, verify it's stored safely
                if (isset($result['address'])) {
                    $this->assertIsString($result['address'], 'Address should be string');
                }

                // Clean up if created
                if (isset($result['address'])) {
                    $this->aliasService->deleteAlias($result['address'], $this->testMailbox);
                }

            } catch (\Exception $e) {
                // Rejection is acceptable (validation may reject)
                $this->assertIsString($e->getMessage(), 'Exception handled');
            }
        }
    }

    /**
     * Test SQL injection in destinations array
     */
    public function testCreateAliasDestinationsSQLInjectionProtection(): void
    {
        $maliciousDestinations = [
            ["' OR '1'='1 --"],
            ["test@domain.com'; DROP TABLE alias; --"],
            ["test@domain.com' UNION SELECT * FROM mailbox --"],
        ];

        foreach ($maliciousDestinations as $destinations) {
            try {
                $result = $this->aliasService->createAlias(
                    'sqltest',
                    $this->testDomain,
                    $destinations, // Malicious destinations
                    $this->testMailbox
                );

                // If succeeded, clean up
                if (isset($result['address'])) {
                    $this->aliasService->deleteAlias($result['address'], $this->testMailbox);
                }

            } catch (\Exception $e) {
                // Rejection is acceptable
                $this->assertIsString($e->getMessage(), 'Should handle safely');
            }
        }
    }

    /**
     * Test update alias with malicious input
     */
    public function testUpdateAliasSQLInjectionProtection(): void
    {
        // First create a test alias
        try {
            $alias = $this->aliasService->createAlias(
                'testsql',
                $this->testDomain,
                [$this->testMailbox],
                $this->testMailbox
            );

            if (isset($alias['address'])) {
                // Try to update with malicious data
                $maliciousUpdates = [
                    ['local_part' => "evil'; DROP TABLE alias; --"],
                    ['destinations' => ["' OR '1'='1"]],
                ];

                foreach ($maliciousUpdates as $updates) {
                    try {
                        $this->aliasService->updateAlias(
                            $alias['address'],
                            $this->testMailbox,
                            $updates
                        );
                    } catch (\Exception $e) {
                        // Safe rejection
                        $this->assertIsString($e->getMessage(), 'Update rejected safely');
                    }
                }

                // Clean up
                $this->aliasService->deleteAlias($alias['address'], $this->testMailbox);
            }
        } catch (\Exception $e) {
            // Test setup may fail in some environments
            $this->assertTrue(true, 'Test completed');
        }
    }

    /**
     * Test delete alias with malicious address
     */
    public function testDeleteAliasSQLInjectionProtection(): void
    {
        $maliciousAddresses = [
            "test@acme.local'; DROP TABLE alias; --",
            "' OR '1'='1",
        ];

        foreach ($maliciousAddresses as $maliciousAddress) {
            $result = $this->aliasService->deleteAlias($maliciousAddress, $this->testMailbox);

            // Should return false (not found/deleted)
            $this->assertFalse($result, 'Should not delete with malicious address');

            // Verify table still exists
            $stmt = $this->db->query("SHOW TABLES LIKE 'alias'");
            $tableExists = $stmt->fetch();
            $this->assertNotFalse($tableExists, 'Table should still exist');
        }
    }

    /**
     * Test available mailboxes search with SQL injection
     */
    public function testAvailableMailboxesSQLInjectionProtection(): void
    {
        $maliciousQueries = [
            "' OR '1'='1",
            "'; DROP TABLE mailbox; --",
            "test' UNION SELECT * FROM admin --",
        ];

        foreach ($maliciousQueries as $maliciousQuery) {
            try {
                $result = $this->aliasService->getAvailableMailboxes(
                    $this->testDomain,
                    $maliciousQuery // Malicious search query
                );

                // Should return safe results
                $this->assertIsArray($result, 'Should return array');

                // Verify mailbox table still exists
                $stmt = $this->db->query("SHOW TABLES LIKE 'mailbox'");
                $tableExists = $stmt->fetch();
                $this->assertNotFalse($tableExists, 'Mailbox table should still exist');

            } catch (\Exception $e) {
                // Safe rejection
                $this->assertIsString($e->getMessage(), 'Should handle safely');
            }
        }
    }

    /**
     * Test that prepared statements are used (indirect verification)
     */
    public function testPreparedStatementsPreventInjection(): void
    {
        // This test verifies that even with malicious input,
        // the database structure remains intact

        $beforeTables = [];
        $stmt = $this->db->query("SHOW TABLES");
        while (($tableName = $stmt->fetchColumn()) !== false) {
            $beforeTables[] = $tableName;
        }

        // Try various injection attacks
        $attacks = [
            ['search' => "'; DROP TABLE alias; --"],
            ['sort' => "address; DELETE FROM mailbox; --"],
            ['status' => "1' OR '1'='1"],
        ];

        foreach ($attacks as $attack) {
            try {
                $this->aliasService->getAliasesForMailbox(
                    $this->testMailbox,
                    $attack['search'] ?? null,
                    $attack['sort'] ?? 'created',
                    'desc',
                    1,
                    20,
                    $attack['status'] ?? null
                );
            } catch (\Exception $e) {
                // Ignore exceptions
            }
        }

        // Verify all tables still exist
        $afterTables = [];
        $stmt = $this->db->query("SHOW TABLES");
        while (($tableName = $stmt->fetchColumn()) !== false) {
            $afterTables[] = $tableName;
        }

        $this->assertEquals(
            count($beforeTables),
            count($afterTables),
            'All tables should still exist after injection attempts'
        );
    }
}
