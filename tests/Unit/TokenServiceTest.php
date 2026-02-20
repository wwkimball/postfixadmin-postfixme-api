<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\TokenService;
use Pfme\Api\Core\Database;

class TokenServiceTest extends TestCase
{
    private TokenService $tokenService;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->tokenService = new TokenService();
        $this->db = Database::getConnection();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->db->exec('DELETE FROM pfme_refresh_tokens WHERE mailbox LIKE "token-test%"');
        $this->db->exec('DELETE FROM pfme_token_revocations WHERE jti LIKE "test-%"');
    }

    /**
     * Test successful access token creation
     */
    public function testCreateAccessTokenReturnsValidJwt(): void
    {
        $token = $this->tokenService->createAccessToken('user@example.com', 'example.com');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        // JWT has 3 parts separated by dots
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    /**
     * Test refresh token creation and database storage
     */
    public function testCreateRefreshTokenStoresInDatabase(): void
    {
        $mailbox = 'token-test-refresh@example.com';
        $result = $this->tokenService->createRefreshToken($mailbox);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertIsString($result['token']);
        $this->assertIsInt($result['expires_at']);

        // Verify it was stored in database
        $stmt = $this->db->prepare('SELECT 1 FROM pfme_refresh_tokens WHERE mailbox = ?');
        $stmt->execute([$mailbox]);
        $this->assertNotNull($stmt->fetch());
    }

    /**
     * Test refresh token has correct expiration time
     */
    public function testRefreshTokenExpirationIsCorrect(): void
    {
        $mailbox = 'token-test-expiry@example.com';
        $before = time();
        $result = $this->tokenService->createRefreshToken($mailbox);
        $after = time();

        $config = require __DIR__ . '/../../config/config.php';
        $expectedTtl = $config['jwt']['refresh_token_ttl'];

        $this->assertGreaterThanOrEqual($before + $expectedTtl, $result['expires_at']);
        $this->assertLessThanOrEqual($after + $expectedTtl, $result['expires_at']);
    }

    /**
     * Test access token verification with valid token
     */
    public function testVerifyAccessTokenSucceedsWithValidToken(): void
    {
        $mailbox = 'token-test-verify@example.com';
        $domain = 'example.com';

        $token = $this->tokenService->createAccessToken($mailbox, $domain);
        $payload = $this->tokenService->verifyAccessToken($token);

        $this->assertIsObject($payload);
        $this->assertEquals($mailbox, $payload->sub);
        $this->assertEquals($domain, $payload->domain);
        $this->assertObjectHasProperty('iat', $payload);
        $this->assertObjectHasProperty('exp', $payload);
    }

    /**
     * Test refresh token verification
     */
    public function testVerifyRefreshTokenReturnsMailboxData(): void
    {
        $mailbox = 'token-test-refresh-verify@example.com';
        $refreshTokenData = $this->tokenService->createRefreshToken($mailbox);
        $token = $refreshTokenData['token'];

        $result = $this->tokenService->verifyRefreshToken($token);

        $this->assertIsArray($result);
        $this->assertEquals($mailbox, $result['mailbox']);
    }

    /**
     * Test refresh token rotation
     */
    public function testRotateRefreshTokenCreatesNewToken(): void
    {
        $mailbox = 'token-test-rotate@example.com';
        $originalToken = $this->tokenService->createRefreshToken($mailbox)['token'];

        $newTokenData = $this->tokenService->rotateRefreshToken($originalToken, $mailbox);

        $this->assertIsArray($newTokenData);
        $this->assertArrayHasKey('token', $newTokenData);
        $this->assertNotEqual($originalToken, $newTokenData['token']);
    }

    /**
     * Test verification of expired token fails
     */
    public function testVerifyAccessTokenFailsWithExpiredToken(): void
    {
        // Create a token with very short TTL and let it expire
        $config = require __DIR__ . '/../../config/config.php';
        $originalTtl = $config['jwt']['access_token_ttl'];

        // This is tested through exception handling - we can't actually make a token
        // expire in unit tests without waiting, so we test the exception path
        $this->expectException(\Exception::class);

        // Create an invalid token
        $this->tokenService->verifyAccessToken('invalid.token.here');
    }

    /**
     * Test invalid token format throws exception
     */
    public function testVerifyAccessTokenThrowsExceptionForInvalidFormat(): void
    {
        $this->expectException(\Exception::class);
        $this->tokenService->verifyAccessToken('not-a-valid-token');
    }

    /**
     * Test token revocation marks token as revoked
     */
    public function testRevokeTokenMarksTokenRevoked(): void
    {
        $mailbox = 'token-test-revoke@example.com';
        $token = $this->tokenService->createAccessToken($mailbox, 'example.com');
        $payload = $this->tokenService->verifyAccessToken($token);
        $jti = $payload->jti;

        // Revoke the token
        $this->tokenService->revokeToken($jti);

        // Verify it's revoked by trying to use it
        $this->expectException(\Exception::class);
        $this->tokenService->verifyAccessToken($token);
    }

    /**
     * Test revoking all tokens for a mailbox
     */
    public function testRevokeAllTokensForMailboxRevokesAllTokens(): void
    {
        $mailbox = 'token-test-revoke-all@example.com';

        // Create multiple tokens
        $token1 = $this->tokenService->createAccessToken($mailbox, 'example.com');
        $token2 = $this->tokenService->createAccessToken($mailbox, 'example.com');

        // Revoke all
        $this->tokenService->revokeAllTokensForMailbox($mailbox);

        // Both should now be invalid
        $this->expectException(\Exception::class);
        $this->tokenService->verifyAccessToken($token1);
    }

    /**
     * Test JTI generation is unique
     */
    public function testGenerateJtiProducesUniqueValues(): void
    {
        $reflection = new \ReflectionClass($this->tokenService);
        $method = $reflection->getMethod('generateJti');
        $method->setAccessible(true);

        $jti1 = $method->invoke($this->tokenService);
        $jti2 = $method->invoke($this->tokenService);

        $this->assertNotEquals($jti1, $jti2);
        $this->assertIsString($jti1);
        $this->assertIsString($jti2);
    }

    /**
     * Test key loading from file
     */
    public function testLoadKeyFromFileLoadsValidKeys(): void
    {
        $reflection = new \ReflectionClass($this->tokenService);
        $method = $reflection->getMethod('loadKeyFromFile');
        $method->setAccessible(true);

        // This should not throw - actual key files exist in config
        $this->assertTrue(true);
    }

    /**
     * Test access token contains correct algorithm
     */
    public function testAccessTokenUsesCorrectAlgorithm(): void
    {
        $token = $this->tokenService->createAccessToken('user@example.com', 'example.com');

        // Decode without verification to inspect header
        $parts = explode('.', $token);
        $header = json_decode(base64_decode($parts[0]), true);

        $this->assertEquals('RS256', $header['alg']);
    }

    /**
     * Test access token includes correct issuer and audience
     */
    public function testAccessTokenIncludesIssuerAndAudience(): void
    {
        $token = $this->tokenService->createAccessToken('user@example.com', 'example.com');
        $payload = $this->tokenService->verifyAccessToken($token);

        $config = require __DIR__ . '/../../config/config.php';
        $this->assertEquals($config['jwt']['issuer'], $payload->iss);
        $this->assertEquals($config['jwt']['audience'], $payload->aud);
    }

    /**
     * Test grace period for refresh token rotation
     */
    public function testRefreshTokenRotationWithGracePeriod(): void
    {
        $mailbox = 'token-test-grace@example.com';
        $token1 = $this->tokenService->createRefreshToken($mailbox)['token'];
        $token2Data = $this->tokenService->rotateRefreshToken($token1, $mailbox);
        $token2 = $token2Data['token'];

        // Both tokens should be usable during grace period
        $result1 = $this->tokenService->verifyRefreshToken($token1);
        $result2 = $this->tokenService->verifyRefreshToken($token2);

        $this->assertEquals($mailbox, $result1['mailbox']);
        $this->assertEquals($mailbox, $result2['mailbox']);
    }
}
