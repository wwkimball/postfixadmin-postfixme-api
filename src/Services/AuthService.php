<?php

namespace Pfme\Api\Services;

use Pfme\Api\Core\Database;

/**
 * Authentication Service - integrates with PostfixAdmin for mailbox authentication
 */
class AuthService
{
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/config.php';
    }

    public function authenticateMailbox(string $mailbox, string $password): bool
    {
        // Validate input
        if (!$this->isValidEmail($mailbox)) {
            return false;
        }

        // Check rate limiting and lockout
        if ($this->isLockedOut($mailbox)) {
            throw new \Exception('Account temporarily locked due to too many failed attempts');
        }

        if ($this->isRateLimited($mailbox)) {
            throw new \Exception('Too many authentication attempts. Please try again later.');
        }

        // Get mailbox from database
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT username, password, active FROM mailbox WHERE username = ?');
        $stmt->execute([$mailbox]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->recordFailedAttempt($mailbox);
            return false;
        }

        if (!$user['active']) {
            $this->recordFailedAttempt($mailbox);
            return false;
        }

        // Verify password using PostfixAdmin's password verification
        $isValid = $this->verifyPassword($password, $user['password']);

        if ($isValid) {
            $this->recordSuccessfulAuth($mailbox);
        } else {
            $this->recordFailedAttempt($mailbox);
        }

        return $isValid;
    }

    private function verifyPassword(string $plainPassword, string $hashedPassword): bool
    {
        // PostfixAdmin supports multiple password schemes
        // Common formats: {SCHEME}hash or just bcrypt/argon2 hash

        // Check for scheme prefix
        if (preg_match('/^\{([^}]+)\}(.+)$/', $hashedPassword, $matches)) {
            $scheme = $matches[1];
            $hash = $matches[2];

            switch (strtoupper($scheme)) {
                case 'CRYPT':
                case 'BLF-CRYPT':
                    return crypt($plainPassword, $hash) === $hash;

                case 'MD5-CRYPT':
                    return crypt($plainPassword, $hash) === $hash;

                case 'SHA256-CRYPT':
                    return crypt($plainPassword, $hash) === $hash;

                case 'SHA512-CRYPT':
                    return crypt($plainPassword, $hash) === $hash;

                case 'MD5':
                    return md5($plainPassword) === $hash;

                case 'SHA256':
                    return hash('sha256', $plainPassword) === $hash;

                case 'ARGON2I':
                case 'ARGON2ID':
                    return password_verify($plainPassword, $hash);

                case 'PLAIN':
                case 'CLEARTEXT':
                    return $plainPassword === $hash;

                default:
                    error_log("Unknown password scheme: {$scheme}");
                    return false;
            }
        }

        // No scheme prefix - try PHP's password_verify (works for bcrypt, argon2)
        if (password_verify($plainPassword, $hashedPassword)) {
            return true;
        }

        // Try crypt
        if (crypt($plainPassword, $hashedPassword) === $hashedPassword) {
            return true;
        }

        return false;
    }

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isRateLimited(string $mailbox): bool
    {
        $window = $this->config['security']['rate_limit_window'];
        $maxAttempts = $this->config['security']['rate_limit_attempts'];

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT COUNT(*) as attempts FROM pfme_auth_log
             WHERE mailbox = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );

        $stmt->execute([$mailbox, $window]);
        $result = $stmt->fetch();

        return ($result['attempts'] ?? 0) >= $maxAttempts;
    }

    private function isLockedOut(string $mailbox): bool
    {
        $duration = $this->config['security']['lockout_duration'];
        $threshold = $this->config['security']['lockout_threshold'];

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT COUNT(*) as attempts FROM pfme_auth_log
             WHERE mailbox = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );

        $stmt->execute([$mailbox]);
        $result = $stmt->fetch();

        if (($result['attempts'] ?? 0) < $threshold) {
            return false;
        }

        // Check if still within lockout period
        $stmt = $db->prepare(
            'SELECT attempted_at FROM pfme_auth_log
             WHERE mailbox = ? AND success = 0
             ORDER BY attempted_at DESC LIMIT 1'
        );

        $stmt->execute([$mailbox]);
        $lastAttempt = $stmt->fetch();

        if ($lastAttempt) {
            $timeSince = time() - strtotime($lastAttempt['attempted_at']);
            return $timeSince < $duration;
        }

        return false;
    }

    private function recordFailedAttempt(string $mailbox): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 0, ?, ?, NOW())'
        );

        $stmt->execute([
            $mailbox,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function recordSuccessfulAuth(string $mailbox): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 1, ?, ?, NOW())'
        );

        $stmt->execute([
            $mailbox,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function isRateLimitedOnRefresh(string $mailbox): bool
    {
        $window = $this->config['security']['rate_limit_window'];
        $maxAttempts = $this->config['security']['rate_limit_attempts'];

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT COUNT(*) as attempts FROM pfme_auth_log
             WHERE mailbox = ? AND success = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)'
        );

        $stmt->execute([$mailbox, $window]);
        $result = $stmt->fetch();

        return ($result['attempts'] ?? 0) >= $maxAttempts;
    }

    public function recordFailedRefreshAttempt(string $mailbox): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 0, ?, ?, NOW())'
        );

        $stmt->execute([
            $mailbox,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function recordSuccessfulRefreshAttempt(string $mailbox): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 1, ?, ?, NOW())'
        );

        $stmt->execute([
            $mailbox,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function getDomainFromMailbox(string $mailbox): ?string
    {
        if (strpos($mailbox, '@') === false) {
            return null;
        }

        return explode('@', $mailbox)[1];
    }
}
