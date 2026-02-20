<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\AuthService;

/**
 * Unit tests for IP address validation and CIDR matching
 * 
 * Tests getTrustedClientIp() and isIpInCidr() functionality
 */
class IpValidationTest extends TestCase
{
    private AuthService $authService;

    protected function setUp(): void
    {
        $this->authService = new AuthService();
    }

    /**
     * Test basic CIDR matching for IPv4
     */
    public function testIPv4CIDRMatching(): void
    {
        $testCases = [
            ['ip' => '192.168.1.100', 'cidr' => '192.168.1.0/24', 'expected' => true],
            ['ip' => '192.168.2.100', 'cidr' => '192.168.1.0/24', 'expected' => false],
            ['ip' => '10.0.0.5', 'cidr' => '10.0.0.0/8', 'expected' => true],
            ['ip' => '11.0.0.5', 'cidr' => '10.0.0.0/8', 'expected' => false],
            ['ip' => '172.16.50.100', 'cidr' => '172.16.0.0/12', 'expected' => true],
            ['ip' => '172.32.50.100', 'cidr' => '172.16.0.0/12', 'expected' => false],
        ];

        foreach ($testCases as $case) {
            $result = $this->invokePrivateMethod(
                $this->authService,
                'isIpInCidr',
                [$case['ip'], $case['cidr']]
            );
            
            $this->assertEquals(
                $case['expected'],
                $result,
                "IP {$case['ip']} should " . ($case['expected'] ? '' : 'not ') . 
                "match CIDR {$case['cidr']}"
            );
        }
    }

    /**
     * Test /32 CIDR (exact IP match)
     */
    public function testExactIPMatch(): void
    {
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.1.100', '192.168.1.100/32']
        );
        $this->assertTrue($result, 'Exact IP should match with /32 CIDR');

        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.1.101', '192.168.1.100/32']
        );
        $this->assertFalse($result, 'Different IP should not match /32 CIDR');
    }

    /**
     * Test /0 CIDR (matches all IPs)
     */
    public function testMatchAllCIDR(): void
    {
        $ips = ['1.2.3.4', '192.168.1.1', '10.0.0.1', '255.255.255.255'];
        
        foreach ($ips as $ip) {
            $result = $this->invokePrivateMethod(
                $this->authService,
                'isIpInCidr',
                [$ip, '0.0.0.0/0']
            );
            $this->assertTrue($result, "IP $ip should match 0.0.0.0/0 CIDR");
        }
    }

    /**
     * Test multiple CIDR ranges (comma-separated)
     */
    public function testMultipleCIDRRanges(): void
    {
        $cidrList = '192.168.1.0/24,10.0.0.0/8,172.16.0.0/12';
        
        $matchingIPs = [
            '192.168.1.50',  // First range
            '10.5.10.20',    // Second range
            '172.20.5.100',  // Third range
        ];
        
        foreach ($matchingIPs as $ip) {
            $result = $this->invokePrivateMethod(
                $this->authService,
                'isIpInCidr',
                [$ip, $cidrList]
            );
            $this->assertTrue($result, "IP $ip should match one of the CIDR ranges");
        }
        
        // Non-matching IP
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['200.100.50.25', $cidrList]
        );
        $this->assertFalse($result, 'IP should not match any CIDR range');
    }

    /**
     * Test localhost IPs
     */
    public function testLocalhostIPs(): void
    {
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['127.0.0.1', '127.0.0.0/8']
        );
        $this->assertTrue($result, 'Localhost should match 127.0.0.0/8');

        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['127.0.0.1', '127.0.0.1/32']
        );
        $this->assertTrue($result, 'Localhost should match exact CIDR');
    }

    /**
     * Test private network ranges (RFC 1918)
     */
    public function testPrivateNetworkRanges(): void
    {
        // 10.0.0.0/8
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['10.50.100.200', '10.0.0.0/8']
        );
        $this->assertTrue($result, '10.x.x.x should match private range');

        // 172.16.0.0/12
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['172.20.5.10', '172.16.0.0/12']
        );
        $this->assertTrue($result, '172.16-31.x.x should match private range');

        // 192.168.0.0/16
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.50.100', '192.168.0.0/16']
        );
        $this->assertTrue($result, '192.168.x.x should match private range');
    }

    /**
     * Test edge case: boundary IPs
     */
    public function testBoundaryIPs(): void
    {
        // First IP in range
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.1.0', '192.168.1.0/24']
        );
        $this->assertTrue($result, 'First IP in range should match');

        // Last IP in range
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.1.255', '192.168.1.0/24']
        );
        $this->assertTrue($result, 'Last IP in range should match');

        // Just outside range
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.2.0', '192.168.1.0/24']
        );
        $this->assertFalse($result, 'IP outside range should not match');
    }

    /**
     * Test invalid IP addresses
     */
    public function testInvalidIPAddresses(): void
    {
        $invalidIPs = [
            '999.999.999.999',
            '192.168.1',
            'not.an.ip.address',
            '',
            '192.168.1.256',
        ];

        foreach ($invalidIPs as $invalidIP) {
            $result = $this->invokePrivateMethod(
                $this->authService,
                'isIpInCidr',
                [$invalidIP, '192.168.1.0/24']
            );
            $this->assertFalse($result, "Invalid IP '$invalidIP' should not match");
        }
    }

    /**
     * Test invalid CIDR notation with invalid IP
     */
    public function testInvalidCIDRNotation(): void
    {
        // Test with completely invalid IP address
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.1.50', 'invalid/24']
        );
        // Should handle gracefully (ip2long returns false for invalid IPs)
        $this->assertIsBool($result, 'Should handle invalid IP in CIDR gracefully');

        // Test CIDR without prefix notation (should be treated as exact IP match)
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.1.0', '192.168.1.0']
        );
        $this->assertTrue($result, 'IP without CIDR prefix should match exactly');
    }

    /**
     * Test IPv6 addresses (if supported)
     */
    public function testIPv6Support(): void
    {
        // Test if IPv6 is supported
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['2001:db8::1', '2001:db8::/32']
        );
        
        // Should either match (if IPv6 supported) or return false safely
        $this->assertIsBool($result, 'Should handle IPv6 addresses');
    }

    /**
     * Test checkSingleCidr helper method
     */
    public function testCheckSingleCidr(): void
    {
        $result = $this->invokePrivateMethod(
            $this->authService,
            'checkSingleCidr',
            ['192.168.1.100', '192.168.1.0/24']
        );
        $this->assertTrue($result, 'Single CIDR check should work');

        $result = $this->invokePrivateMethod(
            $this->authService,
            'checkSingleCidr',
            ['10.0.0.1', '192.168.1.0/24']
        );
        $this->assertFalse($result, 'Single CIDR check should reject non-matching IP');
    }

    /**
     * Test getTrustedClientIp method exists and returns valid format
     */
    public function testGetTrustedClientIpReturnsValidFormat(): void
    {
        $ip = $this->invokePrivateMethod($this->authService, 'getTrustedClientIp');
        
        // Should return null or valid IP string
        if ($ip !== null) {
            $this->assertIsString($ip, 'Client IP should be string');
            // Basic IP format check (very permissive)
            $this->assertMatchesRegularExpression(
                '/^[\d.:]+$/',
                $ip,
                'Should look like an IP address'
            );
        } else {
            $this->assertNull($ip, 'Client IP can be null');
        }
    }

    /**
     * Test whitespace handling in CIDR list
     */
    public function testCIDRListWhitespaceHandling(): void
    {
        $cidrWithSpaces = ' 192.168.1.0/24 , 10.0.0.0/8 , 172.16.0.0/12 ';
        
        $result = $this->invokePrivateMethod(
            $this->authService,
            'isIpInCidr',
            ['192.168.1.50', $cidrWithSpaces]
        );
        $this->assertTrue($result, 'Should handle whitespace in CIDR list');
    }

    /**
     * Helper method to invoke private/protected methods for testing
     */
    private function invokePrivateMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $parameters);
    }
}
