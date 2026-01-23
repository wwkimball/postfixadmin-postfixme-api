<?php

namespace Pfme\Api\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Pfme\Api\Core\Database;

/**
 * Token Service - handles JWT creation and validation
 */
class TokenService
{
    private array $config;
    private string $privateKey;
    private string $publicKey;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/config.php';
        $this->privateKey = file_get_contents($this->config['jwt']['private_key_file']);
        $this->publicKey = file_get_contents($this->config['jwt']['public_key_file']);
    }

    public function createAccessToken(string $mailbox, string $domain): string
    {
        $now = time();
        $payload = [
            'iss' => $this->config['jwt']['issuer'],
            'aud' => $this->config['jwt']['audience'],
            'iat' => $now,
            'exp' => $now + $this->config['jwt']['access_token_ttl'],
            'sub' => $mailbox,
            'domain' => $domain,
            'jti' => $this->generateJti(),
        ];

        return JWT::encode($payload, $this->privateKey, $this->config['jwt']['algorithm']);
    }

    public function createRefreshToken(string $mailbox, string $deviceId = null): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + $this->config['jwt']['refresh_token_ttl'];

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO pfme_refresh_tokens (token, mailbox, device_id, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            hash('sha256', $token),
            $mailbox,
            $deviceId,
            date('Y-m-d H:i:s', $expiresAt),
            date('Y-m-d H:i:s'),
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    public function verifyAccessToken(string $token): object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->publicKey, $this->config['jwt']['algorithm']));

            // Check if token is revoked
            if ($this->isTokenRevoked($decoded->jti ?? '')) {
                throw new \Exception('Token has been revoked');
            }

            return $decoded;
        } catch (\Exception $e) {
            throw new \Exception('Invalid or expired token: ' . $e->getMessage());
        }
    }

    public function verifyRefreshToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT * FROM pfme_refresh_tokens
             WHERE token = ? AND expires_at > NOW() AND revoked_at IS NULL'
        );

        $stmt->execute([$tokenHash]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    public function revokeRefreshToken(string $token): void
    {
        $tokenHash = hash('sha256', $token);

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'UPDATE pfme_refresh_tokens SET revoked_at = NOW() WHERE token = ?'
        );

        $stmt->execute([$tokenHash]);
    }

    public function revokeAccessToken(string $jti): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO pfme_revoked_tokens (jti, revoked_at) VALUES (?, NOW())
             ON DUPLICATE KEY UPDATE revoked_at = NOW()'
        );

        $stmt->execute([$jti]);
    }

    private function isTokenRevoked(string $jti): bool
    {
        if (empty($jti)) {
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT 1 FROM pfme_revoked_tokens WHERE jti = ?');
        $stmt->execute([$jti]);

        return $stmt->fetch() !== false;
    }

    private function generateJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function cleanupExpiredTokens(): void
    {
        $db = Database::getConnection();

        // Clean up expired refresh tokens
        $db->exec('DELETE FROM pfme_refresh_tokens WHERE expires_at < NOW()');

        // Clean up old revoked access tokens (keep for 7 days after expiry)
        $cutoff = date('Y-m-d H:i:s', time() - (7 * 86400));
        $db->prepare('DELETE FROM pfme_revoked_tokens WHERE revoked_at < ?')->execute([$cutoff]);
    }
}
