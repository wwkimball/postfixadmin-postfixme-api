<?php

namespace Pfme\Api\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Pfme\Api\Core\Database;
use Pfme\Api\Core\DatabaseHelper;

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
        $this->privateKey = $this->loadKeyFromFile($this->config['jwt']['private_key_file'], 'private');
        $this->publicKey = $this->loadKeyFromFile($this->config['jwt']['public_key_file'], 'public', $this->privateKey);
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

    public function createRefreshToken(string $mailbox): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = time() + $this->config['jwt']['refresh_token_ttl'];

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO pfme_refresh_tokens (token, mailbox, expires_at, created_at, last_used_at)
             VALUES (?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            hash('sha256', $token),
            $mailbox,
            date('Y-m-d H:i:s', $expiresAt),
            date('Y-m-d H:i:s'),
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

            if ($this->isMailboxTokenInvalidated($decoded->sub ?? '', $decoded->iat ?? 0)) {
                throw new \Exception('Token has been revoked');
            }

            return $decoded;
        } catch (\Exception $e) {
            throw new \Exception('Invalid or expired token: ' . $e->getMessage());
        }
    }

    private function loadKeyFromFile(string $path, string $type, ?string $fallbackPrivate = null): string
    {
        if (!is_file($path)) {
            throw new \RuntimeException("JWT {$type} key file not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Unable to read JWT {$type} key file: {$path}");
        }

        $normalized = trim($raw);
        if ($normalized === '') {
            throw new \RuntimeException("JWT {$type} key file is empty: {$path}");
        }

        if (strpos($normalized, '\\n') !== false && strpos($normalized, '-----BEGIN') === false) {
            $normalized = str_replace('\\n', "\n", $normalized);
        }

        if (strpos($normalized, '-----BEGIN') === false) {
            $normalized = $this->wrapPem($normalized, $type);
        }

        if (!$this->isValidKey($normalized, $type)) {
            if ($type === 'public' && $fallbackPrivate !== null) {
                $derived = $this->derivePublicKey($fallbackPrivate);
                if ($derived !== '' && $this->isValidKey($derived, 'public')) {
                    return $derived;
                }
            }

            throw new \RuntimeException("Invalid {$type} key in file: {$path}");
        }

        return $normalized;
    }

    private function wrapPem(string $keyMaterial, string $type): string
    {
        $label = $type === 'private' ? 'PRIVATE' : 'PUBLIC';
        $base64 = preg_replace('/\s+/', '', $keyMaterial);

        return "-----BEGIN {$label} KEY-----\n"
            . chunk_split($base64, 64, "\n")
            . "-----END {$label} KEY-----";
    }

    private function isValidKey(string $key, string $type): bool
    {
        $resource = $type === 'private'
            ? openssl_pkey_get_private($key)
            : openssl_pkey_get_public($key);

        return $resource !== false;
    }

    private function derivePublicKey(string $privateKey): string
    {
        $resource = openssl_pkey_get_private($privateKey);
        if ($resource === false) {
            return '';
        }

        $details = openssl_pkey_get_details($resource);
        return $details['key'] ?? '';
    }

    public function verifyRefreshToken(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);

        $db = Database::getConnection();
        $dbType = Database::getType();
        $timeComparison = DatabaseHelper::timestampAfterNow('expires_at', $dbType);

        $stmt = $db->prepare(
            "SELECT * FROM pfme_refresh_tokens
             WHERE token = ? AND {$timeComparison} AND revoked_at IS NULL"
        );

        $stmt->execute([$tokenHash]);
        $result = $stmt->fetch();

        if ($result) {
            // Update sliding expiration - extend expiry on use
            $this->extendRefreshTokenExpiry($tokenHash);
        }

        return $result ?: null;
    }

    public function rotateRefreshToken(string $oldToken, string $mailbox): array
    {
        $oldTokenHash = hash('sha256', $oldToken);
        $db = Database::getConnection();
        $dbType = Database::getType();
        $gracePeriod = $this->config['jwt']['refresh_token_grace_period'];

        // Verify old token is still valid or within grace period
        $nowComparison = DatabaseHelper::timestampAfterNow('expires_at', $dbType);
        $gracePeriodComparison = DatabaseHelper::timestampAfterSeconds('expires_at', $gracePeriod, $dbType);

        $stmt = $db->prepare(
            "SELECT * FROM pfme_refresh_tokens
             WHERE token = ? AND revoked_at IS NULL
             AND (
                {$nowComparison}
                OR {$gracePeriodComparison}
             )"
        );

        $stmt->execute([$oldTokenHash]);
        $oldTokenData = $stmt->fetch();

        if (!$oldTokenData) {
            throw new \Exception('Invalid or expired refresh token');
        }

        // SEC-032 mitigation: Detect token reuse - if token was already rotated, it may indicate theft
        // BUT: Allow reuse within grace period (legitimate after app restart/rebuild)
        if (!empty($oldTokenData['rotated_at'])) {
            $rotatedAtTime = strtotime($oldTokenData['rotated_at']);
            $gracePeriod = $this->config['jwt']['refresh_token_grace_period'];
            $graceEndTime = $rotatedAtTime + $gracePeriod;
            $currentTime = time();

            // Only revoke if using old token AFTER grace period expires
            // (within grace period is legitimate for app restarts/rebuilds)
            if ($currentTime > $graceEndTime) {
                // Token reuse detected OUTSIDE grace period - indicates actual theft
                $familyId = $oldTokenData['family_id'] ?: $oldTokenHash;
                $this->revokeTokenFamily($familyId);

                // Log security event
                error_log("Security Alert: Refresh token reuse detected for mailbox {$mailbox} OUTSIDE grace period. Family {$familyId} revoked. Rotation: {$oldTokenData['rotated_at']}, Grace end: " . date('Y-m-d H:i:s', $graceEndTime));

                throw new \Exception('Token reuse detected - possible token theft. All tokens in family have been revoked.');
            }
            // Within grace period - allow rotation to proceed (legitimate restart/rebuild scenario)
        }

        // Check if this token is part of a family that's been revoked
        if ($oldTokenData['family_id'] && $this->isTokenFamilyRevoked($oldTokenData['family_id'])) {
            throw new \Exception('Token family has been revoked - possible token theft detected');
        }

        // Create new token with same family ID
        $newToken = bin2hex(random_bytes(32));
        $newTokenHash = hash('sha256', $newToken);
        $expiresAt = time() + $this->config['jwt']['refresh_token_ttl'];
        $familyId = $oldTokenData['family_id'] ?: $oldTokenHash; // Use old token hash as family ID if not set
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $stmt = $db->prepare(
            "INSERT INTO pfme_refresh_tokens
             (token, mailbox, expires_at, created_at, last_used_at, family_id, rotated_from)
             VALUES (?, ?, ?, {$now}, {$now}, ?, ?)"
        );

        $stmt->execute([
            $newTokenHash,
            $mailbox,
            DatabaseHelper::formatTimestamp($expiresAt, $dbType),
            $familyId,
            $oldTokenHash,
        ]);

        // Mark old token as rotated (keep it valid during grace period)
        $stmt = $db->prepare(
            "UPDATE pfme_refresh_tokens SET rotated_to = ?, rotated_at = {$now} WHERE token = ?"
        );
        $stmt->execute([$newTokenHash, $oldTokenHash]);

        return [
            'token' => $newToken,
            'expires_at' => $expiresAt,
        ];
    }

    private function extendRefreshTokenExpiry(string $tokenHash): void
    {
        $newExpiry = time() + $this->config['jwt']['refresh_token_ttl'];
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "UPDATE pfme_refresh_tokens
             SET expires_at = ?, last_used_at = {$now}
             WHERE token = ?"
        );

        $stmt->execute([
            DatabaseHelper::formatTimestamp($newExpiry, $dbType),
            $tokenHash,
        ]);
    }

    private function isTokenFamilyRevoked(string $familyId): bool
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT 1 FROM pfme_refresh_tokens
             WHERE family_id = ? AND revoked_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([$familyId]);

        return $stmt->fetch() !== false;
    }

    private function revokeTokenFamily(string $familyId): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $stmt = $db->prepare(
            "UPDATE pfme_refresh_tokens
             SET revoked_at = {$now}
             WHERE family_id = ? AND revoked_at IS NULL"
        );
        $stmt->execute([$familyId]);
    }

    public function revokeRefreshToken(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "UPDATE pfme_refresh_tokens SET revoked_at = {$now} WHERE token = ?"
        );

        $stmt->execute([$tokenHash]);
    }

    public function revokeAccessToken(string $jti): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        if ($dbType === 'postgresql') {
            $stmt = $db->prepare(
                "INSERT INTO pfme_revoked_tokens (jti, revoked_at) VALUES (?, {$now})
                 ON CONFLICT (jti) DO UPDATE SET revoked_at = {$now}"
            );
        } elseif ($dbType === 'sqlite') {
            $stmt = $db->prepare(
                "INSERT OR REPLACE INTO pfme_revoked_tokens (jti, revoked_at) VALUES (?, {$now})"
            );
        } else {
            // MySQL/MariaDB
            $stmt = $db->prepare(
                "INSERT INTO pfme_revoked_tokens (jti, revoked_at) VALUES (?, {$now})
                 ON DUPLICATE KEY UPDATE revoked_at = {$now}"
            );
        }

        $stmt->execute([$jti]);
    }

    public function revokeAllTokensForMailbox(string $mailbox, ?string $currentJti = null): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'UPDATE pfme_refresh_tokens SET revoked_at = NOW()
             WHERE mailbox = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$mailbox]);

        if (!empty($currentJti)) {
            $this->revokeAccessToken($currentJti);
        }

        $this->recordPasswordChange($mailbox);
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

    private function isMailboxTokenInvalidated(string $mailbox, int $issuedAt): bool
    {
        if (empty($mailbox) || empty($issuedAt)) {
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT password_changed_at FROM pfme_mailbox_security WHERE mailbox = ?'
        );
        $stmt->execute([$mailbox]);
        $row = $stmt->fetch();

        if (!$row || empty($row['password_changed_at'])) {
            return false;
        }

        $changedAt = strtotime($row['password_changed_at']);
        return $changedAt >= $issuedAt;
    }

    private function recordPasswordChange(string $mailbox): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        if ($dbType === 'postgresql') {
            $stmt = $db->prepare(
                "INSERT INTO pfme_mailbox_security (mailbox, password_changed_at, updated_at)
                 VALUES (?, {$now}, {$now})
                 ON CONFLICT (mailbox) DO UPDATE SET password_changed_at = {$now}, updated_at = {$now}"
            );
        } elseif ($dbType === 'sqlite') {
            $stmt = $db->prepare(
                "INSERT OR REPLACE INTO pfme_mailbox_security (mailbox, password_changed_at, updated_at)
                 VALUES (?, {$now}, {$now})"
            );
        } else {
            // MySQL/MariaDB
            $stmt = $db->prepare(
                "INSERT INTO pfme_mailbox_security (mailbox, password_changed_at, updated_at)
                 VALUES (?, {$now}, {$now})
                 ON DUPLICATE KEY UPDATE password_changed_at = {$now}, updated_at = {$now}"
            );
        }

        $stmt->execute([$mailbox]);
    }

    private function generateJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function cleanupExpiredTokens(): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $timeComparison = DatabaseHelper::timestampBeforeNow('expires_at', $dbType);

        // Clean up expired refresh tokens
        $db->exec("DELETE FROM pfme_refresh_tokens WHERE {$timeComparison}");

        // Clean up old revoked access tokens (keep for 7 days after expiry)
        $cutoff = DatabaseHelper::formatTimestamp(time() - (7 * 86400), $dbType);
        $db->prepare('DELETE FROM pfme_revoked_tokens WHERE revoked_at < ?')->execute([$cutoff]);
    }
}
