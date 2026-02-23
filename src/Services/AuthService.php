<?php

namespace Pfme\Api\Services;

use Pfme\Api\Core\Database;
use Pfme\Api\Core\DatabaseHelper;

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
        $this->loadPostfixAdminAuth();

        if (function_exists('check_password')) {
            return (bool) check_password($plainPassword, $hashedPassword);
        }

        if (function_exists('pacrypt')) {
            $computed = pacrypt($plainPassword, $hashedPassword);
            return hash_equals($hashedPassword, $computed);
        }

        throw new \RuntimeException('PostfixAdmin authentication functions not available.');
    }

    private function loadPostfixAdminAuth(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $sourcePath = rtrim($this->config['postfixadmin']['source_path'] ?? '/usr/src/postfixadmin', '/');
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
        $loaded = true;
    }

    private function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function getTrustedClientIp(): ?string
    {
        // Determine the actual client IP address, respecting trusted proxy configuration
        // If a trusted proxy is configured and the request comes from the trusted CIDR,
        // extract the client IP from the trusted header (default: X-Forwarded-For)
        // Otherwise, use REMOTE_ADDR directly

        $trustedCidr = $this->config['security']['trusted_proxy_cidr'] ?? '';
        $trustedHeader = $this->config['security']['trusted_tls_header'] ?? 'X-Forwarded-Proto';

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;

        if (empty($remoteAddr)) {
            return null;
        }

        // If no trusted proxy is configured, use REMOTE_ADDR directly
        if (empty($trustedCidr)) {
            return $remoteAddr;
        }

        // Check if REMOTE_ADDR is in the trusted CIDR
        if (!$this->isIpInCidr($remoteAddr, $trustedCidr)) {
            return $remoteAddr;
        }

        // Request is from trusted proxy - extract client IP from trusted header
        // Use X-Forwarded-For by default, or a configured header
        $forwardedHeader = $_SERVER['HTTP_X_FORWARDED_FOR'] ??
                          $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($trustedHeader))] ??
                          null;

        if (!empty($forwardedHeader)) {
            // X-Forwarded-For can contain multiple IPs (client, proxy1, proxy2, ...)
            // Per RFC 7239, use the rightmost IP from the trusted proxy
            $ips = array_map('trim', explode(',', $forwardedHeader));
            $clientIp = end($ips);
            if (!empty($clientIp)) {
                return $clientIp;
            }
        }

        // Fallback to REMOTE_ADDR if header extraction fails
        return $remoteAddr;
    }

    private function isIpInCidr(string $ip, string $cidr): bool
    {
        // Check if IP address falls within given CIDR block(s)
        // Supports comma-separated CIDR blocks and individual IPs

        if (empty($cidr)) {
            return false;
        }

        $cidrs = array_map('trim', explode(',', $cidr));

        foreach ($cidrs as $cidrBlock) {
            if ($this->checkSingleCidr($ip, $cidrBlock)) {
                return true;
            }
        }

        return false;
    }

    private function checkSingleCidr(string $ip, string $cidr): bool
    {
        // Check if an IP matches a single CIDR block
        // Supports both CIDR notation (192.168.0.0/24) and individual IPs (192.168.0.1)

        if (strpos($cidr, '/') === false) {
            // Individual IP - exact match
            return $ip === $cidr;
        }

        // CIDR notation - perform bitwise comparison
        list($subnet, $mask) = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int)$mask);
        $maskLong = $maskLong & 0xffffffff; // Ensure 32-bit unsigned integer

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    private function isRateLimited(string $mailbox): bool
    {
        $window = $this->config['security']['rate_limit_window'];
        $maxAttempts = $this->config['security']['rate_limit_attempts'];

        $db = Database::getConnection();
        $dbType = Database::getType();

        $timeComparison = DatabaseHelper::timestampAfterSecondsParam('attempted_at', $dbType);
        $stmt = $db->prepare(
            "SELECT COUNT(*) as attempts FROM pfme_auth_log
             WHERE mailbox = ? AND success = 0 AND {$timeComparison}"
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
        $dbType = Database::getType();

        // Check for recent failed attempts (within one hour)
        $timeComparison = DatabaseHelper::timestampAfterSecondsParam('attempted_at', $dbType);
        $stmt = $db->prepare(
            "SELECT COUNT(*) as attempts FROM pfme_auth_log
             WHERE mailbox = ? AND success = 0 AND {$timeComparison}"
        );

        $stmt->execute([$mailbox, 3600]); // 3600 seconds = 1 hour
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
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $stmt = $db->prepare(
            "INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 0, ?, ?, {$now})"
        );

        $stmt->execute([
            $mailbox,
            $this->getTrustedClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function recordSuccessfulAuth(string $mailbox): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $stmt = $db->prepare(
            "INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 1, ?, ?, {$now})"
        );

        $stmt->execute([
            $mailbox,
            $this->getTrustedClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function isRateLimitedOnRefresh(string $mailbox): bool
    {
        $window = $this->config['security']['rate_limit_window'];
        $maxAttempts = $this->config['security']['rate_limit_attempts'];

        $db = Database::getConnection();
        $dbType = Database::getType();

        $timeComparison = DatabaseHelper::timestampAfterSecondsParam('attempted_at', $dbType);
        $stmt = $db->prepare(
            "SELECT COUNT(*) as attempts FROM pfme_auth_log
             WHERE mailbox = ? AND success = 0 AND {$timeComparison}"
        );

        $stmt->execute([$mailbox, $window]);
        $result = $stmt->fetch();

        return ($result['attempts'] ?? 0) >= $maxAttempts;
    }

    public function recordFailedRefreshAttempt(string $mailbox): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $stmt = $db->prepare(
            "INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 0, ?, ?, {$now})"
        );

        $stmt->execute([
            $mailbox,
            $this->getTrustedClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function recordSuccessfulRefreshAttempt(string $mailbox): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $stmt = $db->prepare(
            "INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 1, ?, ?, {$now})"
        );

        $stmt->execute([
            $mailbox,
            $this->getTrustedClientIp(),
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
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $stmt = $db->prepare(
            "INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 0, ?, ?, {$now})"
        );

        $stmt->execute([
            $mailbox,
            $this->getTrustedClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    public function recordSuccessfulPasswordChangeAttempt(string $mailbox): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);

        $stmt = $db->prepare(
            "INSERT INTO pfme_auth_log (mailbox, success, ip_address, user_agent, attempted_at)
             VALUES (?, 1, ?, ?, {$now})"
        );

        $stmt->execute([
            $mailbox,
            $this->getTrustedClientIp(),
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
        $this->loadPostfixAdminAuth();

        if (!function_exists('pacrypt')) {
            throw new \RuntimeException('PostfixAdmin password hash function not available.');
        }

        return pacrypt($newPassword);
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
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);
        $dateExtractor = DatabaseHelper::extractDate('attempted_at', $dbType);
        $subtractExpr = DatabaseHelper::subtractSeconds($lagDays * 86400, $dbType);
        $failCount = DatabaseHelper::sumCase('success = 0', $dbType);
        $successCount = DatabaseHelper::sumCase('success = 1', $dbType);

        // Database-specific INSERT ... ON DUPLICATE KEY / ON CONFLICT handling
        if ($dbType === 'postgresql') {
            $stmt = $db->prepare(
                "INSERT INTO pfme_auth_log_summary (mailbox, summary_date, failed_attempts, successful_attempts, created_at, updated_at)
                 SELECT mailbox,
                        {$dateExtractor} AS summary_date,
                        {$failCount} AS failed_attempts,
                        {$successCount} AS successful_attempts,
                        {$now},
                        {$now}
                   FROM pfme_auth_log
                  WHERE attempted_at < {$subtractExpr}
                  GROUP BY mailbox, {$dateExtractor}
                 ON CONFLICT (mailbox, summary_date)
                 DO UPDATE SET
                        failed_attempts = EXCLUDED.failed_attempts,
                        successful_attempts = EXCLUDED.successful_attempts,
                        updated_at = {$now}"
            );
        } elseif ($dbType === 'sqlite') {
            $stmt = $db->prepare(
                "INSERT OR REPLACE INTO pfme_auth_log_summary (mailbox, summary_date, failed_attempts, successful_attempts, created_at, updated_at)
                 SELECT mailbox,
                        {$dateExtractor} AS summary_date,
                        {$failCount} AS failed_attempts,
                        {$successCount} AS successful_attempts,
                        {$now},
                        {$now}
                   FROM pfme_auth_log
                  WHERE attempted_at < {$subtractExpr}
                  GROUP BY mailbox, {$dateExtractor}"
            );
        } else {
            // MySQL/MariaDB - use ON DUPLICATE KEY UPDATE
            $stmt = $db->prepare(
                "INSERT INTO pfme_auth_log_summary (mailbox, summary_date, failed_attempts, successful_attempts, created_at, updated_at)
                 SELECT mailbox,
                        {$dateExtractor} AS summary_date,
                        {$failCount} AS failed_attempts,
                        {$successCount} AS successful_attempts,
                        {$now},
                        {$now}
                   FROM pfme_auth_log
                  WHERE attempted_at < {$subtractExpr}
                  GROUP BY mailbox, {$dateExtractor}
                 ON DUPLICATE KEY UPDATE
                        failed_attempts = VALUES(failed_attempts),
                        successful_attempts = VALUES(successful_attempts),
                        updated_at = {$now}"
            );
        }

        $stmt->execute();
    }

    private function deleteOldAuthLogs(int $retentionDays): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $subtractExpr = DatabaseHelper::subtractSeconds($retentionDays * 86400, $dbType);

        $stmt = $db->prepare(
            "DELETE FROM pfme_auth_log
             WHERE attempted_at < {$subtractExpr}"
        );

        $stmt->execute();
    }

    private function archiveOldAuthLogs(int $retentionDays): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $now = DatabaseHelper::now($dbType);
        $subtractExpr = DatabaseHelper::subtractSeconds($retentionDays * 86400, $dbType);

        $stmt = $db->prepare(
            "INSERT INTO pfme_auth_log_archive (mailbox, success, ip_address, user_agent, attempted_at, archived_at)
             SELECT mailbox, success, ip_address, user_agent, attempted_at, {$now}
               FROM pfme_auth_log
              WHERE attempted_at < {$subtractExpr}"
        );
        $stmt->execute();

        $stmt = $db->prepare(
            "DELETE FROM pfme_auth_log
             WHERE attempted_at < {$subtractExpr}"
        );
        $stmt->execute();
    }

    private function cleanupArchivedAuthLogs(int $archiveRetentionDays): void
    {
        $db = Database::getConnection();
        $dbType = Database::getType();
        $subtractExpr = DatabaseHelper::subtractSeconds($archiveRetentionDays * 86400, $dbType);

        $stmt = $db->prepare(
            "DELETE FROM pfme_auth_log_archive
             WHERE archived_at < {$subtractExpr}"
        );

        $stmt->execute();
    }

    public function getDomainFromMailbox(string $mailbox): ?string
    {
        if (strpos($mailbox, '@') === false) {
            return null;
        }

        return explode('@', $mailbox)[1];
    }
}
