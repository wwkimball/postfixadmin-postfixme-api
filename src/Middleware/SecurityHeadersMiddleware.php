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


namespace Pfme\Api\Middleware;

/**
 * Security Headers Middleware - adds security headers to all responses
 */
class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src "none"; frame-ancestors "none"');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if ($this->isTls()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    private function isTls(): bool
    {
        $config = require __DIR__ . '/../../config/config.php';

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $trustedCidr = $config['security']['trusted_proxy_cidr'] ?? '';
        $trustedHeader = $config['security']['trusted_tls_header'] ?? '';

        if (empty($trustedCidr) || empty($trustedHeader)) {
            return false;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->isIpInCidr($remoteAddr, $trustedCidr)) {
            return false;
        }

        $headerValue = $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($trustedHeader))] ?? '';
        return strtolower($headerValue) === 'https';
    }

    private function isIpInCidr(string $ip, string $cidr): bool
    {
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
        if (strpos($cidr, '/') === false) {
            return $ip === $cidr;
        }

        list($subnet, $mask) = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - (int)$mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
}
