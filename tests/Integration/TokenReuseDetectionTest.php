<?php

namespace Pfme\Api\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\TokenService;
use Pfme\Api\Core\Database;

/**
 * Integration test for SEC-032 mitigation: Token reuse detection
 *
 * Tests that rotateRefreshToken() properly detects when a previously-rotated
 * token is presented and revokes the entire token family per OAuth 2.0 BCP (RFC 9700 Section 4.14.2)
 */
class TokenReuseDetectionTest extends TestCase
{
    private TokenService $tokenService;
    private \PDO $db;
    private string $testMailbox = 'test-reuse@example.com';

    protected function setUp(): void
    {
        $this->tokenService = new TokenService();
        $this->db = Database::getConnection();

        // Clean up any existing test tokens
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
    }

    /**
     * Test that token reuse is detected and entire family is revoked
     *
     * SEC-032: Attack scenario
     * 1. Attacker steals refresh token A
     * 2. Legitimate user refreshes: token A rotates to token B (A gets rotated_at set)
     * 3. Attacker tries to use stolen token A - should fail and revoke family
     * NOTE: Grace period allows reuse within configured window (for app restarts)
     */
    public function testTokenReuseDetection(): void
    {
        // Step 1: Create initial refresh token (simulating login)
        $tokenA = $this->tokenService->createRefreshToken($this->testMailbox);
        $this->assertArrayHasKey('token', $tokenA);

        // Step 2: Legitimate user rotates token A to token B
        $tokenB = $this->tokenService->rotateRefreshToken($tokenA['token'], $this->testMailbox);
        $this->assertArrayHasKey('token', $tokenB);
        $this->assertNotEquals($tokenA['token'], $tokenB['token']);

        // Verify token A now has rotated_at set
        $tokenAHash = hash('sha256', $tokenA['token']);
        $stmt = $this->db->prepare('SELECT rotated_at, rotated_to FROM pfme_refresh_tokens WHERE token = ?');
        $stmt->execute([$tokenAHash]);
        $tokenAData = $stmt->fetch();
        $this->assertNotNull($tokenAData['rotated_at'], 'Token A should have rotated_at set');
        $this->assertNotNull($tokenAData['rotated_to'], 'Token A should have rotated_to set');

        // Step 3: Verify token A is still valid (within grace period)
        // The actual behavior depends on grace period configuration
        $tokenAVerification = $this->tokenService->verifyRefreshToken($tokenA['token']);
        if ($tokenAVerification) {
            // Token is still valid during grace period - this is expected behavior
            $this->assertArrayHasKey('mailbox', $tokenAVerification);
            $this->assertEquals($this->testMailbox, $tokenAVerification['mailbox']);
        }
    }

    /**
     * Test that multiple sequential rotations maintain the same family
     * All tokens in the rotation chain should have the same family_id
     */
    public function testMultipleRotationsPreserveFamily(): void
    {
        // Create token A
        $tokenA = $this->tokenService->createRefreshToken($this->testMailbox);

        // Rotate to token B - this establishes the family
        $tokenB = $this->tokenService->rotateRefreshToken($tokenA['token'], $this->testMailbox);
        $tokenBHash = hash('sha256', $tokenB['token']);

        // Get token B's family ID (should be set now)
        $stmt = $this->db->prepare('SELECT family_id FROM pfme_refresh_tokens WHERE token = ?');
        $stmt->execute([$tokenBHash]);
        $tokenBData = $stmt->fetch();
        $familyId = $tokenBData['family_id'];
        $this->assertNotNull($familyId, 'Token B should have a family_id after rotation');

        // Rotate to token C
        $tokenC = $this->tokenService->rotateRefreshToken($tokenB['token'], $this->testMailbox);
        $tokenCHash = hash('sha256', $tokenC['token']);

        // Token C should have same family ID as token B
        $stmt = $this->db->prepare('SELECT family_id FROM pfme_refresh_tokens WHERE token = ?');
        $stmt->execute([$tokenCHash]);
        $tokenCData = $stmt->fetch();
        $this->assertEquals($familyId, $tokenCData['family_id'], 'Token C should have same family ID as token B');

        // Rotate to token D
        $tokenD = $this->tokenService->rotateRefreshToken($tokenC['token'], $this->testMailbox);
        $tokenDHash = hash('sha256', $tokenD['token']);

        // Token D should have same family ID
        $stmt = $this->db->prepare('SELECT family_id FROM pfme_refresh_tokens WHERE token = ?');
        $stmt->execute([$tokenDHash]);
        $tokenDData = $stmt->fetch();
        $this->assertEquals($familyId, $tokenDData['family_id'], 'Token D should have same family ID');
    }

    /**
     * Test that family revocation occurs when token reuse is detected
     */
    public function testFamilyRevocationOnReuse(): void
    {
        // Create token chain: A -> B -> C
        $tokenA = $this->tokenService->createRefreshToken($this->testMailbox);
        $tokenB = $this->tokenService->rotateRefreshToken($tokenA['token'], $this->testMailbox);
        $tokenC = $this->tokenService->rotateRefreshToken($tokenB['token'], $this->testMailbox);

        // Get family_id for verification
        $tokenCHash = hash('sha256', $tokenC['token']);
        $stmt = $this->db->prepare('SELECT family_id FROM pfme_refresh_tokens WHERE token = ?');
        $stmt->execute([$tokenCHash]);
        $familyData = $stmt->fetch();
        $familyId = $familyData['family_id'];
        $this->assertNotNull($familyId);

        // Simulate time passing to be outside grace period (7 days)
        // Update token A's rotated_at to 8 days ago
        $tokenAHash = hash('sha256', $tokenA['token']);
        $stmt = $this->db->prepare(
            'UPDATE pfme_refresh_tokens SET rotated_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE token = ?'
        );
        $stmt->execute([$tokenAHash]);

        // Attacker tries to reuse token A (which was rotated to B, now outside grace period)
        try {
            $this->tokenService->rotateRefreshToken($tokenA['token'], $this->testMailbox);
            $this->fail('Expected exception for token reuse');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Token reuse detected', $e->getMessage());
        }

        // Verify entire family is revoked (tokens A, B, C should all have revoked_at set)
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as count FROM pfme_refresh_tokens
             WHERE family_id = ? AND revoked_at IS NOT NULL'
        );
        $stmt->execute([$familyId]);
        $result = $stmt->fetch();

        // All tokens in family should be revoked
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) as total FROM pfme_refresh_tokens WHERE family_id = ?'
        );
        $stmt->execute([$familyId]);
        $total = $stmt->fetch();

        $this->assertEquals(
            $total['total'],
            $result['count'],
            'All tokens in family should be revoked after reuse detection'
        );
    }

    /**
     * Test that legitimate user's current token also becomes unusable after reuse detection
     *
     * Note: Error message may be either "Token family has been revoked" or
     * "Invalid or expired refresh token" depending on timing - both indicate successful revocation
     */
    public function testLegitimateTokenInvalidatedAfterReuseDetection(): void
    {
        // Create token chain: A -> B
        $tokenA = $this->tokenService->createRefreshToken($this->testMailbox);
        $tokenB = $this->tokenService->rotateRefreshToken($tokenA['token'], $this->testMailbox);

        // Simulate time passing to be outside grace period (7 days)
        // Update token A's rotated_at to 8 days ago
        $tokenAHash = hash('sha256', $tokenA['token']);
        $stmt = $this->db->prepare(
            'UPDATE pfme_refresh_tokens SET rotated_at = DATE_SUB(NOW(), INTERVAL 8 DAY) WHERE token = ?'
        );
        $stmt->execute([$tokenAHash]);

        // Attacker reuses token A - triggers family revocation
        try {
            $this->tokenService->rotateRefreshToken($tokenA['token'], $this->testMailbox);
        } catch (\Exception $e) {
            // Expected
        }

        // Legitimate user tries to use token B - should fail because family was revoked
        try {
            $this->tokenService->rotateRefreshToken($tokenB['token'], $this->testMailbox);
            $this->fail('Expected exception for revoked family token');
        } catch (\Exception $e) {
            // Both messages are valid - token is revoked so it's invalid
            $this->assertTrue(
                str_contains($e->getMessage(), 'Token family has been revoked') ||
                str_contains($e->getMessage(), 'Invalid or expired refresh token'),
                'Expected token to be rejected due to family revocation, got: ' . $e->getMessage()
            );
        }
    }

    /**
     * Test that normal rotation (no reuse) continues to work correctly
     */
    public function testNormalRotationStillWorks(): void
    {
        // Create initial token
        $tokenA = $this->tokenService->createRefreshToken($this->testMailbox);

        // Rotate multiple times without reuse - should work fine
        $tokenB = $this->tokenService->rotateRefreshToken($tokenA['token'], $this->testMailbox);
        $tokenC = $this->tokenService->rotateRefreshToken($tokenB['token'], $this->testMailbox);
        $tokenD = $this->tokenService->rotateRefreshToken($tokenC['token'], $this->testMailbox);

        $this->assertArrayHasKey('token', $tokenD);

        // Verify latest token is valid
        $tokenDHash = hash('sha256', $tokenD['token']);
        $stmt = $this->db->prepare(
            'SELECT * FROM pfme_refresh_tokens
             WHERE token = ? AND revoked_at IS NULL AND rotated_at IS NULL'
        );
        $stmt->execute([$tokenDHash]);
        $result = $stmt->fetch();

        $this->assertNotFalse($result, 'Latest token should be valid and not rotated');
    }
}
