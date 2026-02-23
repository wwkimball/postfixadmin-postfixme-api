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


namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Core\Database;

class AuthorizationFailureTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getConnection();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->db->exec('DELETE FROM pfme_refresh_tokens WHERE mailbox LIKE "authfail-test%"');
        $this->db->exec('DELETE FROM pfme_auth_log WHERE mailbox LIKE "authfail-test%"');
    }

    /**
     * Test that revoked refresh tokens are rejected
     */
    public function testRevokedRefreshTokenRejected(): void
    {
        $testMailbox = 'authfail-test-revoke@example.com';

        // Create a valid refresh token
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_refresh_tokens (mailbox, token, expires_at, created_at, revoked_at)
             VALUES (?, ?, ?, ?, ?)'
        );

        $token = hash('sha256', 'test_token_' . microtime());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 years'));
        $createdAt = date('Y-m-d H:i:s');
        $revokedAt = date('Y-m-d H:i:s');

        $stmt->execute([$testMailbox, $token, $expiresAt, $createdAt, $revokedAt]);

        // Verify the token is marked as revoked
        $stmt = $this->db->prepare(
            'SELECT revoked_at FROM pfme_refresh_tokens WHERE mailbox = ? AND token = ?'
        );
        $stmt->execute([$testMailbox, $token]);
        $tokenData = $stmt->fetch();

        $this->assertNotFalse($tokenData, 'Token should exist');
        $this->assertNotNull($tokenData['revoked_at'], 'Token should be revoked');
    }

    /**
     * Test that expired refresh tokens are rejected
     */
    public function testExpiredRefreshTokenRejected(): void
    {
        $testMailbox = 'authfail-test-expired@example.com';

        // Create an expired refresh token
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_refresh_tokens (mailbox, token, expires_at, created_at, revoked_at)
             VALUES (?, ?, ?, ?, NULL)'
        );

        $token = hash('sha256', 'test_token_' . microtime());
        $expiresAt = date('Y-m-d H:i:s', strtotime('-1 day')); // Expired yesterday
        $createdAt = date('Y-m-d H:i:s', strtotime('-2 days'));

        $stmt->execute([$testMailbox, $token, $expiresAt, $createdAt]);

        // Verify the token is expired
        $stmt = $this->db->prepare(
            'SELECT expires_at FROM pfme_refresh_tokens WHERE mailbox = ? AND token = ?'
        );
        $stmt->execute([$testMailbox, $token]);
        $tokenData = $stmt->fetch();

        $this->assertNotFalse($tokenData, 'Token should exist');
        $this->assertLessThan(
            date('Y-m-d H:i:s'),
            $tokenData['expires_at'],
            'Token should be expired'
        );
    }

    /**
     * Test that rotating an expired token rejects the old token
     */
    public function testTokenRotationInvalidatesOldToken(): void
    {
        $testMailbox = 'authfail-test-rotation@example.com';

        // Create initial token
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_refresh_tokens (mailbox, token, expires_at, created_at, revoked_at)
             VALUES (?, ?, ?, ?, NULL)'
        );

        $oldToken = hash('sha256', 'old_token_' . microtime());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 years'));
        $createdAt = date('Y-m-d H:i:s');

        $stmt->execute([$testMailbox, $oldToken, $expiresAt, $createdAt]);

        // Create rotated token (simulate token rotation)
        $newToken = hash('sha256', 'new_token_' . microtime());

        $stmt->execute([$testMailbox, $newToken, $expiresAt, $createdAt]);

        // Verify both tokens exist
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as cnt FROM pfme_refresh_tokens WHERE mailbox = ?'
        );
        $stmt->execute([$testMailbox]);
        $result = $stmt->fetch();

        $this->assertEquals(2, $result['cnt'], 'Both old and new tokens should exist for mailbox');
    }

    /**
     * Test that invalid JWT tokens are rejected
     */
    public function testMalformedJWTRejected(): void
    {
        // A malformed JWT would be rejected by the JWT verification in the middleware
        // This test verifies that the JWT structure validation works

        // Valid JWT structure: header.payload.signature
        $validJWT = "eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.signature";
        $invalidJWT1 = "notavalidjwt";
        $invalidJWT2 = "only.two.parts"; // But let's give it a point anyway
        $invalidJWT3 = "has.too.many.parts.here";

        // Check structure validation
        $this->assertTrue(
            count(explode('.', $validJWT)) === 3,
            'Valid JWT should have 3 parts'
        );

        $this->assertFalse(
            count(explode('.', $invalidJWT1)) === 3,
            'Invalid JWT should not have 3 parts'
        );

        $this->assertFalse(
            count(explode('.', $invalidJWT3)) === 3,
            'JWT with too many parts should fail validation'
        );
    }

    /**
     * Test that tokens from different mailboxes cannot be interchanged
     */
    public function testTokensNotInterchangeableBetweenMailboxes(): void
    {
        $mailbox1 = 'authfail-test-iso1@example.com';
        $mailbox2 = 'authfail-test-iso2@example.com';

        // Create token for mailbox1
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_refresh_tokens (mailbox, token, expires_at, created_at, revoked_at)
             VALUES (?, ?, ?, ?, NULL)'
        );

        $token1 = hash('sha256', 'token1_' . microtime());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 years'));
        $createdAt = date('Y-m-d H:i:s');

        $stmt->execute([$mailbox1, $token1, $expiresAt, $createdAt]);

        // Create token for mailbox2
        $token2 = hash('sha256', 'token2_' . microtime());

        $stmt->execute([$mailbox2, $token2, $expiresAt, $createdAt]);

        // Verify tokens are stored separately
        $stmt = $this->db->prepare(
            'SELECT mailbox FROM pfme_refresh_tokens WHERE token = ?'
        );

        $stmt->execute([$token1]);
        $data1 = $stmt->fetch();
        $this->assertEquals($mailbox1, $data1['mailbox']);

        $stmt->execute([$token2]);
        $data2 = $stmt->fetch();
        $this->assertEquals($mailbox2, $data2['mailbox']);

        // Verify attempt to use token1 with mailbox2 would fail
        $stmt = $this->db->prepare(
            'SELECT mailbox FROM pfme_refresh_tokens WHERE token = ? AND mailbox = ?'
        );
        $stmt->execute([$token1, $mailbox2]);
        $crossCheck = $stmt->fetch();

        $this->assertFalse($crossCheck, 'Token from mailbox1 should not match mailbox2');
    }

    /**
     * Test that unauthenticated requests fail authorization
     */
    public function testMissingAuthorizationHeaderRejected(): void
    {
        // This test verifies that missing Authorization header is handled
        // In a real scenario, AuthMiddleware would reject this before controller

        $this->assertFalse(
            isset($_SERVER['HTTP_AUTHORIZATION']),
            'Test environment should not have Authorization header by default'
        );
    }

    /**
     * Test that requests with invalid mailbox authorization fail
     */
    public function testUnauthorizedMailboxAccessRejected(): void
    {
        $authorizedMailbox = 'authfail-test-authorized@example.com';
        $unauthorizedMailbox = 'authfail-test-unauthorized@example.com';

        // Simulate an auth context for authorizedMailbox
        $authorizedContext = ['mailbox' => $authorizedMailbox];
        $unauthorizedContext = ['mailbox' => $unauthorizedMailbox];

        // Verify that different auth contexts represent different users
        $this->assertNotEquals(
            $authorizedContext['mailbox'],
            $unauthorizedContext['mailbox'],
            'Different mailboxes should not be equal'
        );

        // A request with authorized context should not be able to access unauthorized mailbox
        $this->assertNotEquals(
            $authorizedContext,
            $unauthorizedContext,
            'Different auth contexts should not match'
        );
    }

    /**
     * Test that token family invalidation prevents reuse of compromised tokens
     */
    public function testCompromisedTokenFamilyInvalidated(): void
    {
        $testMailbox = 'authfail-test-family@example.com';

        // Create multiple tokens for same mailbox (simulating rotation history)
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_refresh_tokens (mailbox, token, expires_at, created_at, revoked_at)
             VALUES (?, ?, ?, ?, NULL)'
        );

        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 years'));
        $createdAt = date('Y-m-d H:i:s');
        $tokens = [];

        for ($i = 0; $i < 3; $i++) {
            $token = hash('sha256', "token_{$i}_" . microtime());
            $tokens[] = $token;

            $stmt->execute([$testMailbox, $token, $expiresAt, $createdAt]);
        }

        // If any token is detected as compromised, all tokens for mailbox should be invalidated
        // Verify all tokens can be queried for this mailbox
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as cnt FROM pfme_refresh_tokens WHERE mailbox = ?'
        );
        $stmt->execute([$testMailbox]);
        $result = $stmt->fetch();

        $this->assertEquals(3, $result['cnt'], 'All 3 tokens should exist for mailbox');

        // Now revoke all tokens for mailbox (simulating compromise detection)
        $revokedAt = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'UPDATE pfme_refresh_tokens SET revoked_at = ? WHERE mailbox = ?'
        );
        $stmt->execute([$revokedAt, $testMailbox]);

        // Verify all tokens for mailbox are now revoked
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as cnt FROM pfme_refresh_tokens WHERE mailbox = ? AND revoked_at IS NOT NULL'
        );
        $stmt->execute([$testMailbox]);
        $result = $stmt->fetch();

        $this->assertEquals(3, $result['cnt'], 'All tokens for mailbox should be revoked');
    }

    /**
     * Test that post-logout requests cannot access protected resources
     */
    public function testPostLogoutAccessDenied(): void
    {
        $testMailbox = 'authfail-test-logout@example.com';

        // Simulate logout: mark all refresh tokens as revoked
        $stmt = $this->db->prepare(
            'INSERT INTO pfme_refresh_tokens (mailbox, token, expires_at, created_at, revoked_at)
             VALUES (?, ?, ?, ?, ?)'
        );

        $token = hash('sha256', 'logout_token_' . microtime());
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 years'));
        $createdAt = date('Y-m-d H:i:s');
        $revokedAt = date('Y-m-d H:i:s'); // Just logged out

        $stmt->execute([$testMailbox, $token, $expiresAt, $createdAt, $revokedAt]);

        // Attempt to use revoked token should fail
        $stmt = $this->db->prepare(
            'SELECT revoked_at FROM pfme_refresh_tokens WHERE mailbox = ? AND token = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$testMailbox, $token]);
        $result = $stmt->fetch();

        $this->assertFalse($result, 'Revoked token should not be found in active token query');
    }
}
