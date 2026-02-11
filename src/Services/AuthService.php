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
                    return hash_equals($hash, crypt($plainPassword, $hash));

                case 'MD5-CRYPT':
                    return hash_equals($hash, crypt($plainPassword, $hash));

                case 'SHA256-CRYPT':
                    return hash_equals($hash, crypt($plainPassword, $hash));

                case 'SHA512-CRYPT':
                    return hash_equals($hash, crypt($plainPassword, $hash));

                case 'MD5':
                    return hash_equals($hash, md5($plainPassword));

                case 'SHA256':
                    return hash_equals($hash, hash('sha256', $plainPassword));

                case 'ARGON2I':
                case 'ARGON2ID':
                    return password_verify($plainPassword, $hash);

                case 'PLAIN':
                case 'CLEARTEXT':
                    return hash_equals($hash, $plainPassword);

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
        if (hash_equals($hashedPassword, crypt($plainPassword, $hashedPassword))) {
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

    public function isRateLimitedOnPasswordChange(string $mailbox): bool
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

    public function recordFailedPasswordChangeAttempt(string $mailbox): void
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

    public function recordSuccessfulPasswordChangeAttempt(string $mailbox): void
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

    public function changeMailboxPassword(string $mailbox, string $currentPassword, string $newPassword): void
    {
        if (!$this->isValidEmail($mailbox)) {
            throw new \RuntimeException('Invalid mailbox', 401);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT username, password, active FROM mailbox WHERE username = ?');
        $stmt->execute([$mailbox]);
        $user = $stmt->fetch();

        if (!$user || !$user['active']) {
            throw new \RuntimeException('Invalid mailbox', 401);
        }

        if (!$this->verifyPassword($currentPassword, $user['password'])) {
            throw new \RuntimeException('Current password is incorrect', 401);
        }

        $policy = $this->validatePasswordPolicy($newPassword, $currentPassword);
        if (!$policy['valid']) {
            throw new \InvalidArgumentException(implode(' ', $policy['errors']));
        }

        $newHash = $this->hashPasswordForExistingScheme($newPassword, $user['password']);

        $stmt = $db->prepare('UPDATE mailbox SET password = ? WHERE username = ?');
        $stmt->execute([$newHash, $mailbox]);
    }

    public function validatePasswordPolicy(string $newPassword, string $currentPassword): array
    {
        $errors = [];

        $minLength = $this->config['security']['password_min_length'] ?? 10;
        $requireSpace = $this->config['security']['password_require_space'] ?? true;
        $requireGrammar = $this->config['security']['password_require_grammar_symbol'] ?? true;

        if (strlen($newPassword) < $minLength) {
            $errors[] = "Passphrase must be at least {$minLength} characters.";
        }

        if ($requireSpace && !preg_match('/\s/', $newPassword)) {
            $errors[] = 'Passphrase must include at least one space.';
        }

        if ($requireGrammar && !preg_match('/[.\,!?;:\'"\-\(\)\[\]\{\}@#$%^&*]/', $newPassword)) {
            $errors[] = 'Passphrase must include at least one grammar symbol (. , ! ? ; : \' " - ( ) [ ] { } @ # $ % ^ & *).';
        }

        if (hash_equals($newPassword, $currentPassword)) {
            $errors[] = 'New passphrase must be different from current password.';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function hashPasswordForExistingScheme(string $newPassword, string $existingHash): string
    {
        if (preg_match('/^\{([^}]+)\}(.+)$/', $existingHash, $matches)) {
            $scheme = strtoupper($matches[1]);
            $hash = $matches[2];

            switch ($scheme) {
                case 'CRYPT':
                case 'BLF-CRYPT':
                case 'MD5-CRYPT':
                case 'SHA256-CRYPT':
                case 'SHA512-CRYPT':
                    return '{' . $scheme . '}' . crypt($newPassword, $hash);

                case 'MD5':
                    return '{' . $scheme . '}' . md5($newPassword);

                case 'SHA256':
                    return '{' . $scheme . '}' . hash('sha256', $newPassword);

                case 'ARGON2I':
                    return '{' . $scheme . '}' . password_hash($newPassword, PASSWORD_ARGON2I);

                case 'ARGON2ID':
                    return '{' . $scheme . '}' . password_hash($newPassword, PASSWORD_ARGON2ID);

                case 'PLAIN':
                case 'CLEARTEXT':
                    return '{' . $scheme . '}' . $newPassword;

                default:
                    return '{' . $scheme . '}' . password_hash($newPassword, PASSWORD_DEFAULT);
            }
        }

        return password_hash($newPassword, PASSWORD_DEFAULT);
    }

    public function maintainAuthLogs(): void
    {
        $retentionDays = (int)($this->config['security']['auth_log_retention_days'] ?? 90);
        $summaryEnabled = (bool)($this->config['security']['auth_log_summary_enabled'] ?? true);
        $summaryLagDays = (int)($this->config['security']['auth_log_summary_lag_days'] ?? 1);
        $archiveEnabled = (bool)($this->config['security']['auth_log_archive_enabled'] ?? false);
        $archiveRetentionDays = (int)($this->config['security']['auth_log_archive_retention_days'] ?? 365);

        if ($summaryEnabled) {
            $this->aggregateAuthLogSummary(max(0, $summaryLagDays));
        }

        if ($retentionDays <= 0) {
            return; // Retention disabled
        }

        if ($archiveEnabled) {
            $this->archiveOldAuthLogs($retentionDays);

            if ($archiveRetentionDays > 0) {
                $this->cleanupArchivedAuthLogs($archiveRetentionDays);
            }
        } else {
            $this->deleteOldAuthLogs($retentionDays);
        }
    }

    private function aggregateAuthLogSummary(int $lagDays): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'INSERT INTO pfme_auth_log_summary (mailbox, summary_date, failed_attempts, successful_attempts, created_at, updated_at)
             SELECT mailbox,
                    DATE(attempted_at) AS summary_date,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) AS failed_attempts,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) AS successful_attempts,
                    NOW(),
                    NOW()
               FROM pfme_auth_log
              WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)
              GROUP BY mailbox, DATE(attempted_at)
             ON DUPLICATE KEY UPDATE
                    failed_attempts = VALUES(failed_attempts),
                    successful_attempts = VALUES(successful_attempts),
                    updated_at = NOW()'
        );

        $stmt->execute([$lagDays]);
    }

    private function deleteOldAuthLogs(int $retentionDays): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'DELETE FROM pfme_auth_log
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );

        $stmt->execute([$retentionDays]);
    }

    private function archiveOldAuthLogs(int $retentionDays): void
    {
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'INSERT INTO pfme_auth_log_archive (mailbox, success, ip_address, user_agent, attempted_at, archived_at)
             SELECT mailbox, success, ip_address, user_agent, attempted_at, NOW()
               FROM pfme_auth_log
              WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$retentionDays]);

        $stmt = $db->prepare(
            'DELETE FROM pfme_auth_log
             WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );
        $stmt->execute([$retentionDays]);
    }

    private function cleanupArchivedAuthLogs(int $archiveRetentionDays): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'DELETE FROM pfme_auth_log_archive
             WHERE archived_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
        );

        $stmt->execute([$archiveRetentionDays]);
    }

    public function getDomainFromMailbox(string $mailbox): ?string
    {
        if (strpos($mailbox, '@') === false) {
            return null;
        }

        return explode('@', $mailbox)[1];
    }
}
