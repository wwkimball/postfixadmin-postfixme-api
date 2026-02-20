<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\AuthService;
use Pfme\Api\Core\Database;

/**
 * Unit tests for AuthService rate limiting and lockout functionality
 *
 * Tests SEC-033 (Rate Limiting) and SEC-034 (Account Lockout) mitigations
 */
class AuthServiceRateLimitingTest extends TestCase
{
    private AuthService $authService;
    private \PDO $db;
    private string $testMailbox = 'ratelimit-test@acme.local';

    protected function setUp(): void
    {
        $this->authService = new AuthService();
        $this->db = Database::getConnection();

        // Clean up any existing test data
        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
    }

    private function cleanupTestData(): void
    {
        $stmt = $this->db->prepare('DELETE FROM pfme_auth_log WHERE mailbox = ?');
        $stmt->execute([$this->testMailbox]);
    }

    /**
     * Test that rate limiting is not triggered under normal conditions
     */
    public function testNotRateLimitedWithNoRecentAttempts(): void
    {
        $result = $this->invokePrivateMethod($this->authService, 'isRateLimited', [$this->testMailbox]);
        $this->assertFalse($result, 'Should not be rate limited with no recent attempts');
    }

    /**
     * Test that rate limiting triggers after excessive failed attempts
     * SEC-033: Rate limiting should block after configured threshold
     */
    public function testRateLimitingTriggersAfterMultipleFailures(): void
    {
        // Simulate 5 failed attempts within rate limit window
        for ($i = 0; $i < 5; $i++) {
            $this->invokePrivateMethod($this->authService, 'recordFailedAttempt', [$this->testMailbox]);
        }

        $result = $this->invokePrivateMethod($this->authService, 'isRateLimited', [$this->testMailbox]);
        $this->assertTrue($result, 'Should be rate limited after multiple failed attempts');
    }

    /**
     * Test that successful auth resets lockout counter
     */
    public function testSuccessfulAuthResetsFailureCount(): void
    {
        // Record some failures
        for ($i = 0; $i < 3; $i++) {
            $this->invokePrivateMethod($this->authService, 'recordFailedAttempt', [$this->testMailbox]);
        }

        // Record success
        $this->invokePrivateMethod($this->authService, 'recordSuccessfulAuth', [$this->testMailbox]);

        // Should not be rate limited after success
        $result = $this->invokePrivateMethod($this->authService, 'isRateLimited', [$this->testMailbox]);
        $this->assertFalse($result, 'Rate limiting should reset after successful auth');
    }

    /**
     * Test that account lockout triggers after too many failures
     * SEC-034: Account should lock after threshold exceeded
     */
    public function testAccountLockoutAfterExcessiveFailures(): void
    {
        // Simulate excessive failed attempts (typically 10+)
        for ($i = 0; $i < 12; $i++) {
            $this->invokePrivateMethod($this->authService, 'recordFailedAttempt', [$this->testMailbox]);
        }

        $result = $this->invokePrivateMethod($this->authService, 'isLockedOut', [$this->testMailbox]);
        $this->assertTrue($result, 'Account should be locked out after excessive failures');
    }

    /**
     * Test lockout is not triggered prematurely
     */
    public function testLockoutNotTriggeredPrematurely(): void
    {
        // Record moderate number of failures
        for ($i = 0; $i < 5; $i++) {
            $this->invokePrivateMethod($this->authService, 'recordFailedAttempt', [$this->testMailbox]);
        }

        $result = $this->invokePrivateMethod($this->authService, 'isLockedOut', [$this->testMailbox]);
        $this->assertFalse($result, 'Account should not be locked out with moderate failures');
    }

    /**
     * Test rate limiting on refresh token endpoint
     */
    public function testRefreshTokenRateLimiting(): void
    {
        $notLimited = $this->authService->isRateLimitedOnRefresh($this->testMailbox);
        $this->assertFalse($notLimited, 'Should not be rate limited initially on refresh');

        // Trigger multiple refresh failures
        for ($i = 0; $i < 5; $i++) {
            $this->authService->recordFailedRefreshAttempt($this->testMailbox);
        }

        $isLimited = $this->authService->isRateLimitedOnRefresh($this->testMailbox);
        $this->assertTrue($isLimited, 'Should be rate limited after multiple refresh failures');
    }

    /**
     * Test successful refresh resets rate limiting
     */
    public function testSuccessfulRefreshResetsRateLimiting(): void
    {
        // Record failures
        for ($i = 0; $i < 3; $i++) {
            $this->authService->recordFailedRefreshAttempt($this->testMailbox);
        }

        // Record success
        $this->authService->recordSuccessfulRefreshAttempt($this->testMailbox);

        $isLimited = $this->authService->isRateLimitedOnRefresh($this->testMailbox);
        $this->assertFalse($isLimited, 'Rate limiting should reset after successful refresh');
    }

    /**
     * Test rate limiting on password change endpoint
     */
    public function testPasswordChangeRateLimiting(): void
    {
        $notLimited = $this->authService->isRateLimitedOnPasswordChange($this->testMailbox);
        $this->assertFalse($notLimited, 'Should not be rate limited initially on password change');

        // Trigger multiple password change failures
        for ($i = 0; $i < 5; $i++) {
            $this->authService->recordFailedPasswordChangeAttempt($this->testMailbox);
        }

        $isLimited = $this->authService->isRateLimitedOnPasswordChange($this->testMailbox);
        $this->assertTrue($isLimited, 'Should be rate limited after multiple password change failures');
    }

    /**
     * Test successful password change resets rate limiting
     */
    public function testSuccessfulPasswordChangeResetsRateLimiting(): void
    {
        // Record failures
        for ($i = 0; $i < 3; $i++) {
            $this->authService->recordFailedPasswordChangeAttempt($this->testMailbox);
        }

        // Record success
        $this->authService->recordSuccessfulPasswordChangeAttempt($this->testMailbox);

        $isLimited = $this->authService->isRateLimitedOnPasswordChange($this->testMailbox);
        $this->assertFalse($isLimited, 'Rate limiting should reset after successful password change');
    }

    /**
     * Test that auth log entries are created correctly
     */
    public function testAuthLogEntriesCreated(): void
    {
        $this->invokePrivateMethod($this->authService, 'recordFailedAttempt', [$this->testMailbox]);

        $stmt = $this->db->prepare('SELECT COUNT(*) as count FROM pfme_auth_log WHERE mailbox = ?');
        $stmt->execute([$this->testMailbox]);
        $result = $stmt->fetch();

        $this->assertGreaterThan(0, $result['count'], 'Auth log should contain entries');
    }

    /**
     * Test that successful auth records success flag correctly
     */
    public function testSuccessfulAuthRecordsCorrectly(): void
    {
        $this->invokePrivateMethod($this->authService, 'recordSuccessfulAuth', [$this->testMailbox]);

        $stmt = $this->db->prepare(
            'SELECT success FROM pfme_auth_log WHERE mailbox = ? ORDER BY attempted_at DESC LIMIT 1'
        );
        $stmt->execute([$this->testMailbox]);
        $result = $stmt->fetch();

        $this->assertEquals(1, $result['success'], 'Success flag should be 1 for successful auth');
    }

    /**
     * Test that failed auth records success flag correctly
     */
    public function testFailedAuthRecordsCorrectly(): void
    {
        $this->invokePrivateMethod($this->authService, 'recordFailedAttempt', [$this->testMailbox]);

        $stmt = $this->db->prepare(
            'SELECT success FROM pfme_auth_log WHERE mailbox = ? ORDER BY attempted_at DESC LIMIT 1'
        );
        $stmt->execute([$this->testMailbox]);
        $result = $stmt->fetch();

        $this->assertEquals(0, $result['success'], 'Success flag should be 0 for failed auth');
    }

    /**
     * Helper method to invoke private/protected methods for testing
     */
    private function invokePrivateMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
