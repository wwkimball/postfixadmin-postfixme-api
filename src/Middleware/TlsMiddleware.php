<?php

namespace Pfme\Api\Middleware;

/**
 * TLS Middleware - enforces TLS connections
 */
class TlsMiddleware implements MiddlewareInterface
{
    public function handle(): void
    {
        $config = require __DIR__ . '/../../config/config.php';

        if (!$config['security']['require_tls']) {
            return;
        }

        $isTls = $this->checkTls($config);

        if (!$isTls) {
            http_response_code(403);
            echo json_encode([
                'code' => 'tls_required',
                'message' => 'TLS connection required',
            ]);
            exit;
        }
    }

    private function checkTls(array $config): bool
    {
        // Check direct HTTPS
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        // Check trusted proxy header
        $trustedCidr = $config['security']['trusted_proxy_cidr'];
        $trustedHeader = $config['security']['trusted_tls_header'];

        if (empty($trustedCidr) || empty($trustedHeader)) {
            return false;
        }

        // Verify request is from trusted proxy
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!$this->isIpInCidr($remoteAddr, $trustedCidr)) {
            return false;
        }

        // Check the proxy header
        $headerValue = $_SERVER['HTTP_' . str_replace('-', '_', strtoupper($trustedHeader))] ?? '';
        return strtolower($headerValue) === 'https';
    }

    private function isIpInCidr(string $ip, string $cidr): bool
    {
        if (empty($cidr)) {
            return false;
        }

        // Support comma-separated CIDRs
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
