<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Middleware\TlsMiddleware;

class TlsMiddlewareTest extends TestCase
{
    private TlsMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new TlsMiddleware();
    }

    public function testIsIpInCidr(): void
    {
        $reflection = new \ReflectionClass($this->middleware);
        $method = $reflection->getMethod('isIpInCidr');
        $method->setAccessible(true);

        // Test single IP
        $result = $method->invoke($this->middleware, '192.168.1.100', '192.168.1.100');
        $this->assertTrue($result);

        // Test CIDR range
        $result = $method->invoke($this->middleware, '192.168.1.100', '192.168.1.0/24');
        $this->assertTrue($result);

        $result = $method->invoke($this->middleware, '192.168.2.100', '192.168.1.0/24');
        $this->assertFalse($result);

        // Test multiple CIDRs
        $result = $method->invoke($this->middleware, '10.0.0.1', '192.168.1.0/24,10.0.0.0/8');
        $this->assertTrue($result);
    }

    public function testCheckSingleCidr(): void
    {
        $reflection = new \ReflectionClass($this->middleware);
        $method = $reflection->getMethod('checkSingleCidr');
        $method->setAccessible(true);

        // Test /32 (single IP)
        $result = $method->invoke($this->middleware, '192.168.1.1', '192.168.1.1/32');
        $this->assertTrue($result);

        // Test /24 subnet
        $result = $method->invoke($this->middleware, '192.168.1.50', '192.168.1.0/24');
        $this->assertTrue($result);

        $result = $method->invoke($this->middleware, '192.168.2.50', '192.168.1.0/24');
        $this->assertFalse($result);

        // Test /16 subnet
        $result = $method->invoke($this->middleware, '10.20.30.40', '10.20.0.0/16');
        $this->assertTrue($result);

        $result = $method->invoke($this->middleware, '10.21.30.40', '10.20.0.0/16');
        $this->assertFalse($result);
    }
}
