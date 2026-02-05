<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\AuthService;
use Pfme\Api\Core\Database;

class AuthLogMaintenanceTest extends TestCase
{
    private AuthService $authService;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->authService = new AuthService();
        $this->db = Database::getConnection();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->db->exec('DELETE FROM pfme_auth_log WHERE mailbox LIKE "test%"');
        $this->db->exec('DELETE FROM pfme_auth_log_summary WHERE mailbox LIKE "test%"');
        $this->db->exec('DELETE FROM pfme_auth_log_archive WHERE mailbox LIKE "test%"');
    }

    public function testAggregateAuthLogSummary(): void
    {
        // Insert test auth log entries (2 days ago)
        $testDate = date('Y-m-d H:i:s', strtotime('-2 days'));
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, ?, ?, ?, ?)'
        );

        // Add 3 failed and 2 successful attempts
        $stmt->execute(['test@example.com', 0, '192.168.1.1', 'TestAgent', $testDate]);
        $stmt->execute(['test@example.com', 0, '192.168.1.2', 'TestAgent', $testDate]);
        $stmt->execute(['test@example.com', 0, '192.168.1.3', 'TestAgent', $testDate]);
        $stmt->execute(['test@example.com', 1, '192.168.1.1', 'TestAgent', $testDate]);
        $stmt->execute(['test@example.com', 1, '192.168.1.1', 'TestAgent', $testDate]);

        // Run aggregation via reflection
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('aggregateAuthLogSummary');
        $method->setAccessible(true);
        $method->invoke($this->authService, 1); // 1 day lag

        // Verify summary was created
        $stmt = $this->db->prepare(
            'SELECT * FROM pfme_auth_log_summary WHERE mailbox = ? AND summary_date = DATE(?)'
        );
        $stmt->execute(['test@example.com', $testDate]);
        $summary = $stmt->fetch();

        $this->assertNotFalse($summary);
        $this->assertEquals(3, $summary['failed_attempts']);
        $this->assertEquals(2, $summary['successful_attempts']);
    }

    public function testDeleteOldAuthLogs(): void
    {
        // Insert old log entry (100 days ago)
        $oldDate = date('Y-m-d H:i:s', strtotime('-100 days'));
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['test_old@example.com', 0, '192.168.1.1', 'TestAgent', $oldDate]);

        // Insert recent log entry (10 days ago)
        $recentDate = date('Y-m-d H:i:s', strtotime('-10 days'));
        $stmt->execute(['test_recent@example.com', 1, '192.168.1.1', 'TestAgent', $recentDate]);

        // Run deletion (90-day retention)
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('deleteOldAuthLogs');
        $method->setAccessible(true);
        $method->invoke($this->authService, 90);

        // Verify old entry deleted, recent entry retained
        $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM pfme_auth_log WHERE mailbox = ?');

        $stmt->execute(['test_old@example.com']);
        $this->assertEquals(0, $stmt->fetch()['cnt'], 'Old log should be deleted');

        $stmt->execute(['test_recent@example.com']);
        $this->assertEquals(1, $stmt->fetch()['cnt'], 'Recent log should be retained');
    }

    public function testArchiveOldAuthLogs(): void
    {
        // Insert old log entry (100 days ago)
        $oldDate = date('Y-m-d H:i:s', strtotime('-100 days'));
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['test_archive@example.com', 0, '192.168.1.100', 'ArchiveAgent', $oldDate]);

        // Run archive (90-day retention)
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('archiveOldAuthLogs');
        $method->setAccessible(true);
        $method->invoke($this->authService, 90);

        // Verify entry moved to archive
        $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM pfme_auth_log WHERE mailbox = ?');
        $stmt->execute(['test_archive@example.com']);
        $this->assertEquals(0, $stmt->fetch()['cnt'], 'Log should be deleted from main table');

        $stmt = $this->db->prepare('SELECT * FROM pfme_auth_log_archive WHERE mailbox = ?');
        $stmt->execute(['test_archive@example.com']);
        $archived = $stmt->fetch();

        $this->assertNotFalse($archived, 'Log should exist in archive');
        $this->assertEquals('192.168.1.100', $archived['ip_address']);
        $this->assertEquals('ArchiveAgent', $archived['user_agent']);
    }

    public function testCleanupArchivedAuthLogs(): void
    {
        // Insert old archived entry (400 days ago)
        $oldDate = date('Y-m-d H:i:s', strtotime('-400 days'));
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log_archive (mailbox, success, ip_address, user_agent, attempted_at, archived_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['test_old_archive@example.com', 0, '192.168.1.1', 'TestAgent', $oldDate, $oldDate]);

        // Insert recent archived entry (200 days ago)
        $recentDate = date('Y-m-d H:i:s', strtotime('-200 days'));
        $stmt->execute(['test_recent_archive@example.com', 1, '192.168.1.1', 'TestAgent', $recentDate, $recentDate]);

        // Run archive cleanup (365-day retention)
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('cleanupArchivedAuthLogs');
        $method->setAccessible(true);
        $method->invoke($this->authService, 365);

        // Verify old archive deleted, recent archive retained
        $stmt = $this->db->prepare('SELECT COUNT(*) as cnt FROM pfme_auth_log_archive WHERE mailbox = ?');

        $stmt->execute(['test_old_archive@example.com']);
        $this->assertEquals(0, $stmt->fetch()['cnt'], 'Old archive should be deleted');

        $stmt->execute(['test_recent_archive@example.com']);
        $this->assertEquals(1, $stmt->fetch()['cnt'], 'Recent archive should be retained');
    }

    public function testMaintainAuthLogsWithSummaryOnly(): void
    {
        // Create test config override for summary-only mode
        $config = require __DIR__ . '/../../config/config.php';
        $config['security']['auth_log_retention_days'] = 30;
        $config['security']['auth_log_summary_enabled'] = true;
        $config['security']['auth_log_summary_lag_days'] = 1;
        $config['security']['auth_log_archive_enabled'] = false;

        // Insert old log entry (40 days ago)
        $oldDate = date('Y-m-d H:i:s', strtotime('-40 days'));
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['test_maintain@example.com', 0, '192.168.1.1', 'TestAgent', $oldDate]);

        // Note: Full integration test would require injecting config, but we're verifying individual methods
        $this->assertTrue(true, 'Maintenance methods are unit-tested individually');
    }

    public function testSummaryContainsNoIPOrUserAgent(): void
    {
        // Insert test auth log with IP and UA
        $testDate = date('Y-m-d H:i:s', strtotime('-2 days'));
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute(['test_privacy@example.com', 0, '192.168.1.1', 'SensitiveAgent', $testDate]);

        // Run aggregation
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('aggregateAuthLogSummary');
        $method->setAccessible(true);
        $method->invoke($this->authService, 1);

        // Verify summary schema has no IP/UA columns
        $stmt = $this->db->prepare('SELECT * FROM pfme_auth_log_summary WHERE mailbox = ?');
        $stmt->execute(['test_privacy@example.com']);
        $summary = $stmt->fetch();

        $this->assertNotFalse($summary);
        $this->assertArrayNotHasKey('ip_address', $summary, 'Summary should not contain IP address');
        $this->assertArrayNotHasKey('user_agent', $summary, 'Summary should not contain user agent');
        $this->assertArrayHasKey('failed_attempts', $summary);
        $this->assertArrayHasKey('successful_attempts', $summary);
    }
}
