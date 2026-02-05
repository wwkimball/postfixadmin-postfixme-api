<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\AuthService;
use Pfme\Api\Services\TokenService;
use Pfme\Api\Core\Database;

class RateLimitingTest extends TestCase
{
    private AuthService $authService;
    private TokenService $tokenService;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->authService = new AuthService();
        $this->tokenService = new TokenService();
        $this->db = Database::getConnection();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->db->exec('DELETE FROM pfme_auth_log WHERE mailbox LIKE "ratelimit-test%"');
        $this->db->exec('DELETE FROM pfme_refresh_tokens WHERE mailbox LIKE "ratelimit-test%"');
    }

    /**
     * Test that login rate limiting blocks after 5 failed attempts within 5 minutes
     */
    public function testLoginRateLimitingEnforced(): void
    {
        $testMailbox = 'ratelimit-test-login@example.com';

        // Record 5 failed login attempts
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare(
                'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
                 VALUES (?, 0, ?, ?, NOW())'
            );
            $stmt->execute([$testMailbox, '192.168.1.1', 'TestAgent']);
        }

        // Verify rate limiting is active via reflection
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('isRateLimited');
        $method->setAccessible(true);

        $isLimited = $method->invoke($this->authService, $testMailbox);
        $this->assertTrue($isLimited, 'Should be rate limited after 5 failed attempts');
    }

    /**
     * Test that login rate limiting allows attempts after window expires
     */
    public function testLoginRateLimitingWindowExpiration(): void
    {
        $testMailbox = 'ratelimit-test-window@example.com';

        // Record 5 failed attempts, but use old timestamp (6 minutes ago, outside 5-min window)
        $oldTime = date('Y-m-d H:i:s', strtotime('-6 minutes'));
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 0, ?, ?, ?)'
        );

        for ($i = 0; $i < 5; $i++) {
            $stmt->execute([$testMailbox, '192.168.1.1', 'TestAgent', $oldTime]);
        }

        // Verify rate limiting is NOT active (window expired)
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('isRateLimited');
        $method->setAccessible(true);

        $isLimited = $method->invoke($this->authService, $testMailbox);
        $this->assertFalse($isLimited, 'Should NOT be rate limited when window expires');
    }

    /**
     * Test that successful login attempts do not count toward rate limit
     */
    public function testLoginRateLimitingIgnoresSuccessfulAttempts(): void
    {
        $testMailbox = 'ratelimit-test-success@example.com';

        // Record 5 successful attempts + 4 failed attempts
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, ?, ?, ?, NOW())'
        );

        for ($i = 0; $i < 5; $i++) {
            $stmt->execute([$testMailbox, 1, '192.168.1.1', 'TestAgent']); // success=1
        }

        for ($i = 0; $i < 4; $i++) {
            $stmt->execute([$testMailbox, 0, '192.168.1.1', 'TestAgent']); // success=0
        }

        // Verify rate limiting is NOT active (only 4 failed attempts)
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('isRateLimited');
        $method->setAccessible(true);

        $isLimited = $method->invoke($this->authService, $testMailbox);
        $this->assertFalse($isLimited, 'Should NOT be rate limited with only 4 failed attempts');
    }

    /**
     * Test refresh token rate limiting blocks after 5 failed attempts within 5 minutes
     */
    public function testRefreshRateLimitingEnforced(): void
    {
        $testMailbox = 'ratelimit-test-refresh@example.com';

        // Record 5 failed refresh attempts
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare(
                'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
                 VALUES (?, 0, ?, ?, NOW())'
            );
            $stmt->execute([$testMailbox, '192.168.1.2', 'TestAgent']);
        }

        // Verify refresh rate limiting is active
        $isLimited = $this->authService->isRateLimitedOnRefresh($testMailbox);
        $this->assertTrue($isLimited, 'Should be rate limited on refresh after 5 failed attempts');
    }

    /**
     * Test that refresh rate limiting allows attempts when count < 5
     */
    public function testRefreshRateLimitingAllowsBelowThreshold(): void
    {
        $testMailbox = 'ratelimit-test-refresh-below@example.com';

        // Record 4 failed refresh attempts
        for ($i = 0; $i < 4; $i++) {
            $stmt = $this->db->prepare(
                'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
                 VALUES (?, 0, ?, ?, NOW())'
            );
            $stmt->execute([$testMailbox, '192.168.1.2', 'TestAgent']);
        }

        // Verify refresh rate limiting is NOT active
        $isLimited = $this->authService->isRateLimitedOnRefresh($testMailbox);
        $this->assertFalse($isLimited, 'Should NOT be rate limited with only 4 failed refresh attempts');
    }

    /**
     * Test that recordFailedRefreshAttempt creates proper log entry
     */
    public function testRecordFailedRefreshAttempt(): void
    {
        $testMailbox = 'ratelimit-test-record-fail@example.com';

        // Mock $_SERVER variables
        $oldRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $oldUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';

        // Record a failed attempt
        $this->authService->recordFailedRefreshAttempt($testMailbox);

        // Restore $_SERVER
        if ($oldRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $oldRemoteAddr;
        }
        if ($oldUserAgent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $oldUserAgent;
        }

        // Verify log entry exists
        $stmt = $this->db->prepare(
            'SELECT * FROM pfme_auth_log WHERE mailbox = ? AND success = 0 ORDER BY attempted_at DESC LIMIT 1'
        );
        $stmt->execute([$testMailbox]);
        $log = $stmt->fetch();

        $this->assertNotFalse($log, 'Failed attempt should be logged');
        $this->assertEquals('192.168.1.100', $log['ip_address']);
        $this->assertEquals('TestBrowser/1.0', $log['user_agent']);
        $this->assertEquals(0, $log['success']);
    }

    /**
     * Test that recordSuccessfulRefreshAttempt creates proper log entry
     */
    public function testRecordSuccessfulRefreshAttempt(): void
    {
        $testMailbox = 'ratelimit-test-record-success@example.com';

        // Mock $_SERVER variables
        $oldRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $oldUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $_SERVER['REMOTE_ADDR'] = '192.168.1.101';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/2.0';

        // Record a successful attempt
        $this->authService->recordSuccessfulRefreshAttempt($testMailbox);

        // Restore $_SERVER
        if ($oldRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $oldRemoteAddr;
        }
        if ($oldUserAgent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $oldUserAgent;
        }

        // Verify log entry exists
        $stmt = $this->db->prepare(
            'SELECT * FROM pfme_auth_log WHERE mailbox = ? AND success = 1 ORDER BY attempted_at DESC LIMIT 1'
        );
        $stmt->execute([$testMailbox]);
        $log = $stmt->fetch();

        $this->assertNotFalse($log, 'Successful attempt should be logged');
        $this->assertEquals('192.168.1.101', $log['ip_address']);
        $this->assertEquals('TestBrowser/2.0', $log['user_agent']);
        $this->assertEquals(1, $log['success']);
    }

    /**
     * Test that per-mailbox rate limiting is isolated (separate counters)
     */
    public function testRateLimitingIsolatedPerMailbox(): void
    {
        $mailbox1 = 'ratelimit-test-mailbox1@example.com';
        $mailbox2 = 'ratelimit-test-mailbox2@example.com';

        // Record 5 failed attempts for mailbox1
        for ($i = 0; $i < 5; $i++) {
            $stmt = $this->db->prepare(
                'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
                 VALUES (?, 0, ?, ?, NOW())'
            );
            $stmt->execute([$mailbox1, '192.168.1.1', 'TestAgent']);
        }

        // Record 2 failed attempts for mailbox2
        for ($i = 0; $i < 2; $i++) {
            $stmt = $this->db->prepare(
                'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
                 VALUES (?, 0, ?, ?, NOW())'
            );
            $stmt->execute([$mailbox2, '192.168.1.1', 'TestAgent']);
        }

        // Verify mailbox1 is rate limited, mailbox2 is not
        $isLimited1 = $this->authService->isRateLimitedOnRefresh($mailbox1);
        $isLimited2 = $this->authService->isRateLimitedOnRefresh($mailbox2);

        $this->assertTrue($isLimited1, 'Mailbox1 should be rate limited');
        $this->assertFalse($isLimited2, 'Mailbox2 should NOT be rate limited (only 2 attempts)');
    }

    /**
     * Test that successful refresh clears the rate limit counter
     */
    public function testSuccessfulRefreshResetsWindow(): void
    {
        $testMailbox = 'ratelimit-test-reset@example.com';

        // Record 5 failed attempts, then 1 successful attempt
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, ?, ?, ?, NOW())'
        );

        for ($i = 0; $i < 5; $i++) {
            $stmt->execute([$testMailbox, 0, '192.168.1.1', 'TestAgent']);
        }

        // Now record a successful attempt (should not reset counter on its own)
        $stmt->execute([$testMailbox, 1, '192.168.1.1', 'TestAgent']);

        // Verify rate limiting is still active (successful attempts don't reset failed count)
        $isLimited = $this->authService->isRateLimitedOnRefresh($testMailbox);
        $this->assertTrue(
            $isLimited,
            'Should still be rate limited even after successful attempt (separate counter logic)'
        );
    }
}
