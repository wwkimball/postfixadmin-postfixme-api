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
use Pfme\Api\Services\AuthService;
use Pfme\Api\Services\TokenService;
use Pfme\Api\Core\Database;

/**
 * Integration test for full authentication workflow
 *
 * Tests complete flow: login -> access token -> refresh -> change password -> logout
 * Uses pre-seeded test data from test-data/seeds directory
 */
class AuthenticationFlowTest extends TestCase
{
    private AuthService $authService;
    private TokenService $tokenService;
    private \PDO $db;
    private static bool $postfixAdminAuthLoaded = false;

    // Test credentials from seed data
    private string $testMailbox = 'user1@acme.local';
    private string $testPassword = 'testpass123';
    private string $testDomain = 'acme.local';

    protected function setUp(): void
    {
        $this->authService = new AuthService();
        $this->tokenService = new TokenService();
        $this->db = Database::getConnection();

        // Clean up any test tokens
        $this->cleanupTestTokens();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestTokens();
    }

    private function cleanupTestTokens(): void
    {
        $stmt = $this->db->prepare('DELETE FROM pfme_refresh_tokens WHERE mailbox = ?');
        $stmt->execute([$this->testMailbox]);

        $stmt = $this->db->prepare('DELETE FROM pfme_revoked_tokens');
        $stmt->execute();

        // Reset auth attempts for the seeded mailbox to avoid rate limiting/lockout
        $stmt = $this->db->prepare('DELETE FROM pfme_auth_log WHERE mailbox = ?');
        $stmt->execute([$this->testMailbox]);

        // Reset mailbox security metadata to avoid unintended token invalidation across tests
        $stmt = $this->db->prepare('DELETE FROM pfme_mailbox_security WHERE mailbox = ?');
        $stmt->execute([$this->testMailbox]);
    }

    private function loadPostfixAdminAuth(): void
    {
        if (self::$postfixAdminAuthLoaded) {
            return;
        }

        $config = require __DIR__ . '/../../config/config.php';
        $sourcePath = rtrim($config['postfixadmin']['source_path'] ?? '/usr/src/postfixadmin', '/');
        $configDefault = $sourcePath . '/config.inc.php';
        $configLocal = $sourcePath . '/config.local.php';
        $functions = $sourcePath . '/functions.inc.php';

        if (is_file($configDefault)) {
            require_once $configDefault;
        }

        if (!is_file($configLocal)) {
            throw new \RuntimeException("PostfixAdmin config.local.php not found at: {$configLocal}");
        }

        if (!is_file($functions)) {
            throw new \RuntimeException("PostfixAdmin functions.inc.php not found at: {$functions}");
        }

        require_once $configLocal;
        require_once $functions;

        self::$postfixAdminAuthLoaded = true;
    }

    private function setMailboxPassword(string $mailbox, string $password): void
    {
        $this->loadPostfixAdminAuth();
        $hash = pacrypt($password);
        $stmt = $this->db->prepare('UPDATE mailbox SET password = ?, active = 1 WHERE username = ?');
        $stmt->execute([$hash, $mailbox]);
    }

    /**
     * Test complete authentication workflow from login to logout
     * This tests the happy path of user authentication
     */
    public function testCompleteAuthenticationWorkflow(): void
    {
        // Step 1: Authenticate user with valid credentials
        $authenticated = $this->authService->authenticateMailbox($this->testMailbox, $this->testPassword);
        $this->assertTrue($authenticated, 'User should authenticate with valid credentials');

        // Step 2: Create access and refresh tokens after successful auth
        $accessToken = $this->tokenService->createAccessToken($this->testMailbox, $this->testDomain);
        $this->assertNotEmpty($accessToken, 'Should create access token');

        $refreshTokenData = $this->tokenService->createRefreshToken($this->testMailbox);
        $this->assertArrayHasKey('token', $refreshTokenData, 'Should create refresh token');
        $this->assertArrayHasKey('expires_at', $refreshTokenData, 'Refresh token should have expiry');

        $refreshToken = $refreshTokenData['token'];

        // Step 3: Verify access token is valid
        $decodedToken = $this->tokenService->verifyAccessToken($accessToken);
        $this->assertEquals($this->testMailbox, $decodedToken->sub, 'Token should contain correct mailbox');
        $this->assertEquals($this->testDomain, $decodedToken->domain, 'Token should contain correct domain');

        // Step 4: Verify refresh token is valid
        $refreshVerification = $this->tokenService->verifyRefreshToken($refreshToken);
        $this->assertNotNull($refreshVerification, 'Refresh token should be valid');
        $this->assertEquals($this->testMailbox, $refreshVerification['mailbox'], 'Refresh token should match mailbox');

        // Step 5: Rotate refresh token (simulate token refresh)
        $newRefreshTokenData = $this->tokenService->rotateRefreshToken($refreshToken, $this->testMailbox);
        $this->assertArrayHasKey('token', $newRefreshTokenData, 'Should create new refresh token');
        $this->assertNotEquals($refreshToken, $newRefreshTokenData['token'], 'New token should be different');

        // Step 6: Old refresh token should still be valid during grace period
        $oldTokenVerification = $this->tokenService->verifyRefreshToken($refreshToken);
        if ($oldTokenVerification) {
            $this->assertEquals($this->testMailbox, $oldTokenVerification['mailbox'], 'Old token valid in grace period');
        }

        // Step 7: Logout - revoke all tokens for this mailbox
        $decodedNewToken = $this->tokenService->verifyAccessToken(
            $this->tokenService->createAccessToken($this->testMailbox, $this->testDomain)
        );
        $this->tokenService->revokeAllTokensForMailbox($this->testMailbox, $decodedNewToken->jti);

        // Step 8: Verify tokens are revoked after logout
        // New refresh token should not work after revocation
        try {
            $this->tokenService->rotateRefreshToken($newRefreshTokenData['token'], $this->testMailbox);
            $this->fail('Revoked token should not be rotatable');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Invalid', $e->getMessage(), 'Should indicate token is invalid');
        }
    }

    /**
     * Test authentication failure with incorrect password
     */
    public function testAuthenticationFailsWithIncorrectPassword(): void
    {
        $authenticated = $this->authService->authenticateMailbox($this->testMailbox, 'wrongpassword');
        $this->assertFalse($authenticated, 'Authentication should fail with wrong password');
    }

    /**
     * Test authentication failure with non-existent mailbox
     */
    public function testAuthenticationFailsWithNonExistentMailbox(): void
    {
        $authenticated = $this->authService->authenticateMailbox('nonexistent@acme.local', $this->testPassword);
        $this->assertFalse($authenticated, 'Authentication should fail with non-existent mailbox');
    }

    /**
     * Test that invalid access token is rejected
     */
    public function testInvalidAccessTokenIsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->tokenService->verifyAccessToken('invalid.token.here');
    }

    /**
     * Test that invalid refresh token returns null
     */
    public function testInvalidRefreshTokenReturnsNull(): void
    {
        $result = $this->tokenService->verifyRefreshToken('invalid_refresh_token');
        $this->assertNull($result, 'Invalid refresh token should return null');
    }

    /**
     * Test password change workflow
     */
    public function testPasswordChangeWorkflow(): void
    {
        // Ensure the test mailbox starts with the expected password
        $this->setMailboxPassword($this->testMailbox, $this->testPassword);

        // Authenticate with original password
        $authenticated = $this->authService->authenticateMailbox($this->testMailbox, $this->testPassword);
        $this->assertTrue($authenticated, 'Should authenticate with original password');

        // Change password
        $newPassword = 'New Secure Pass 123!@';

        try {
            $this->authService->changeMailboxPassword($this->testMailbox, $this->testPassword, $newPassword);

            // Verify old password no longer works
            $oldPasswordAuth = $this->authService->authenticateMailbox($this->testMailbox, $this->testPassword);
            $this->assertFalse($oldPasswordAuth, 'Old password should not work after change');

            // Verify new password works
            $newPasswordAuth = $this->authService->authenticateMailbox($this->testMailbox, $newPassword);
            $this->assertTrue($newPasswordAuth, 'New password should work after change');
        } catch (\Exception $e) {
            throw $e;
        } finally {
            // Always reset the mailbox password directly in the DB to the expected seed value
            $this->setMailboxPassword($this->testMailbox, $this->testPassword);
        }
    }

    /**
     * Test that access token contains required claims
     */
    public function testAccessTokenContainsRequiredClaims(): void
    {
        $accessToken = $this->tokenService->createAccessToken($this->testMailbox, $this->testDomain);
        $decoded = $this->tokenService->verifyAccessToken($accessToken);

        // Verify required JWT claims
        $this->assertObjectHasProperty('sub', $decoded, 'Token should have subject claim');
        $this->assertObjectHasProperty('iss', $decoded, 'Token should have issuer claim');
        $this->assertObjectHasProperty('aud', $decoded, 'Token should have audience claim');
        $this->assertObjectHasProperty('exp', $decoded, 'Token should have expiration claim');
        $this->assertObjectHasProperty('iat', $decoded, 'Token should have issued-at claim');
        $this->assertObjectHasProperty('jti', $decoded, 'Token should have JWT ID claim');
        $this->assertObjectHasProperty('domain', $decoded, 'Token should have domain claim');
    }

    /**
     * Test that refresh tokens are properly stored in database
     */
    public function testRefreshTokensAreStoredInDatabase(): void
    {
        $refreshTokenData = $this->tokenService->createRefreshToken($this->testMailbox);
        $tokenHash = hash('sha256', $refreshTokenData['token']);

        $stmt = $this->db->prepare('SELECT * FROM pfme_refresh_tokens WHERE token = ?');
        $stmt->execute([$tokenHash]);
        $storedToken = $stmt->fetch();

        $this->assertNotFalse($storedToken, 'Refresh token should be stored in database');
        $this->assertEquals($this->testMailbox, $storedToken['mailbox'], 'Stored mailbox should match');
        $this->assertNull($storedToken['revoked_at'], 'New token should not be revoked');
    }

    /**
     * Test multiple sequential token rotations
     */
    public function testMultipleSequentialTokenRotations(): void
    {
        $tokenA = $this->tokenService->createRefreshToken($this->testMailbox);
        $tokenB = $this->tokenService->rotateRefreshToken($tokenA['token'], $this->testMailbox);
        $tokenC = $this->tokenService->rotateRefreshToken($tokenB['token'], $this->testMailbox);
        $tokenD = $this->tokenService->rotateRefreshToken($tokenC['token'], $this->testMailbox);

        // All tokens should be different
        $this->assertNotEquals($tokenA['token'], $tokenB['token'], 'Token B should differ from A');
        $this->assertNotEquals($tokenB['token'], $tokenC['token'], 'Token C should differ from B');
        $this->assertNotEquals($tokenC['token'], $tokenD['token'], 'Token D should differ from C');

        // Latest token should be valid
        $verification = $this->tokenService->verifyRefreshToken($tokenD['token']);
        $this->assertNotNull($verification, 'Latest token should be valid');
    }

    /**
     * Test that domain is correctly extracted from mailbox
     */
    public function testDomainExtractionFromMailbox(): void
    {
        $domain = $this->authService->getDomainFromMailbox($this->testMailbox);
        $this->assertEquals($this->testDomain, $domain, 'Should extract correct domain from mailbox');
    }

    /**
     * Test authentication with different seed data mailboxes
     */
    public function testAuthenticationWithMultipleMailboxes(): void
    {
        $mailboxes = [
            'user1@acme.local',
            'user2@acme.local',
            'admin@acme.local',
        ];

        foreach ($mailboxes as $mailbox) {
            $authenticated = $this->authService->authenticateMailbox($mailbox, $this->testPassword);
            $this->assertTrue($authenticated, "Should authenticate mailbox: $mailbox");
        }
    }
}
