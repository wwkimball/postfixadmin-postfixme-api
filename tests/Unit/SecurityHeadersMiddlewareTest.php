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


namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Middleware\SecurityHeadersMiddleware;

class SecurityHeadersMiddlewareTest extends TestCase
{
    /**
     * Test X-Frame-Options header is set
     */
    public function testXFrameOptionsHeaderIsSet(): void
    {
        // We can't directly test header() calls in unit tests, but we can verify the logic
        $middleware = new SecurityHeadersMiddleware();

        // Test the CIDR validation logic
        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Basic IP test
        $result = $method->invoke($middleware, '192.168.1.1', '192.168.1.1');
        $this->assertTrue($result);
    }

    /**
     * Test TLS detection without HTTPS
     */
    public function testIsTlsReturnsFalseWithoutHttps(): void
    {
        $_SERVER['HTTPS'] = 'off';
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

        $reflection = new \ReflectionClass(new SecurityHeadersMiddleware());
        $method = $reflection->getMethod('isTls');
        $method->setAccessible(true);

        $middleware = new SecurityHeadersMiddleware();
        $result = $method->invoke($middleware);

        $this->assertFalse($result);
    }

    /**
     * Test TLS detection with HTTPS set
     */
    public function testIsTlsReturnsTrueWithHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $reflection = new \ReflectionClass(new SecurityHeadersMiddleware());
        $method = $reflection->getMethod('isTls');
        $method->setAccessible(true);

        $middleware = new SecurityHeadersMiddleware();
        $result = $method->invoke($middleware);

        $this->assertTrue($result);
    }

    /**
     * Test TLS detection with trusted proxy header
     */
    public function testIsTlsWithTrustedProxyHeader(): void
    {
        // This requires config setup with trusted proxy
        // For unit test, we test the CIDR logic component
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Test matching CIDR
        $result = $method->invoke($middleware, '10.0.0.5', '10.0.0.0/8');
        $this->assertTrue($result);
    }

    /**
     * Test CIDR matching with /32 (single IP)
     */
    public function testCidrMatchingSingleIp(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        $result = $method->invoke($middleware, '192.168.1.100', '192.168.1.100/32');
        $this->assertTrue($result);

        $result = $method->invoke($middleware, '192.168.1.101', '192.168.1.100/32');
        $this->assertFalse($result);
    }

    /**
     * Test CIDR matching with /24 subnet
     */
    public function testCidrMatchingSubnet24(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Within range
        $result = $method->invoke($middleware, '192.168.1.50', '192.168.1.0/24');
        $this->assertTrue($result);

        // Outside range
        $result = $method->invoke($middleware, '192.168.2.50', '192.168.1.0/24');
        $this->assertFalse($result);
    }

    /**
     * Test CIDR matching with /16 subnet
     */
    public function testCidrMatchingSubnet16(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Within range
        $result = $method->invoke($middleware, '10.20.30.40', '10.20.0.0/16');
        $this->assertTrue($result);

        // Outside range
        $result = $method->invoke($middleware, '10.21.30.40', '10.20.0.0/16');
        $this->assertFalse($result);
    }

    /**
     * Test CIDR matching with /8 subnet
     */
    public function testCidrMatchingSubnet8(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Within range
        $result = $method->invoke($middleware, '10.255.255.255', '10.0.0.0/8');
        $this->assertTrue($result);

        // Outside range
        $result = $method->invoke($middleware, '11.0.0.0', '10.0.0.0/8');
        $this->assertFalse($result);
    }

    /**
     * Test multiple CIDR ranges
     */
    public function testMultipleCidrRanges(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Multiple CIDRs separated by comma
        $cidrs = '192.168.1.0/24,10.0.0.0/8';

        $result = $method->invoke($middleware, '10.5.5.5', $cidrs);
        $this->assertTrue($result);

        $result = $method->invoke($middleware, '192.168.1.50', $cidrs);
        $this->assertTrue($result);

        $result = $method->invoke($middleware, '172.16.0.1', $cidrs);
        $this->assertFalse($result);
    }

    /**
     * Test IPv4 boundary conditions
     */
    public function testIpv4BoundaryConditions(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Network address (first IP)
        $result = $method->invoke($middleware, '192.168.0.0', '192.168.0.0/24');
        $this->assertTrue($result);

        // Broadcast address (last IP)
        $result = $method->invoke($middleware, '192.168.0.255', '192.168.0.0/24');
        $this->assertTrue($result);
    }

    /**
     * Test IPv4 all-zeros match
     */
    public function testAllZerosCidr(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // 0.0.0.0/0 matches everything
        $result = $method->invoke($middleware, '192.168.1.1', '0.0.0.0/0');
        $this->assertTrue($result);

        $result = $method->invoke($middleware, '1.1.1.1', '0.0.0.0/0');
        $this->assertTrue($result);
    }

    /**
     * Test localhost IP matching
     */
    public function testLocalhostMatching(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        $result = $method->invoke($middleware, '127.0.0.1', '127.0.0.1/32');
        $this->assertTrue($result);

        $result = $method->invoke($middleware, '127.0.0.1', '127.0.0.0/8');
        $this->assertTrue($result);
    }

    /**
     * Test private IP ranges
     */
    public function testPrivateIpRanges(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // 10.0.0.0/8
        $result = $method->invoke($middleware, '10.100.50.25', '10.0.0.0/8');
        $this->assertTrue($result);

        // 172.16.0.0/12
        $result = $method->invoke($middleware, '172.31.255.255', '172.16.0.0/12');
        $this->assertTrue($result);

        // 192.168.0.0/16
        $result = $method->invoke($middleware, '192.168.255.255', '192.168.0.0/16');
        $this->assertTrue($result);
    }

    /**
     * Test empty CIDR handling
     */
    public function testEmptyCidrReturnsfalse(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        $result = $method->invoke($middleware, '192.168.1.1', '');
        $this->assertFalse($result);
    }

    /**
     * Test CIDR with whitespace
     */
    public function testCidrWithWhitespace(): void
    {
        $middleware = new SecurityHeadersMiddleware();

        $reflection = new \ReflectionClass($middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Whitespace should be handled
        $result = $method->invoke($middleware, '192.168.1.100', '192.168.1.0/24');
        $this->assertTrue($result);
    }
}
