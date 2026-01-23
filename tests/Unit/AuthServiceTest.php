<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\AuthService;

class AuthServiceTest extends TestCase
{
    private AuthService $authService;

    protected function setUp(): void
    {
        $this->authService = new AuthService();
    }

    public function testGetDomainFromMailbox(): void
    {
        $domain = $this->authService->getDomainFromMailbox('user@example.com');
        $this->assertEquals('example.com', $domain);
    }

    public function testGetDomainFromMailboxWithoutAt(): void
    {
        $domain = $this->authService->getDomainFromMailbox('invalid-email');
        $this->assertNull($domain);
    }

    public function testPasswordVerification(): void
    {
        // Test bcrypt password
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('verifyPassword');
        $method->setAccessible(true);

        // Create a bcrypt hash for testing
        $password = 'testpassword';
        $hash = password_hash($password, PASSWORD_BCRYPT);

        $result = $method->invoke($this->authService, $password, $hash);
        $this->assertTrue($result);

        $result = $method->invoke($this->authService, 'wrongpassword', $hash);
        $this->assertFalse($result);
    }

    public function testPasswordVerificationWithMD5Scheme(): void
    {
        $reflection = new \ReflectionClass($this->authService);
        $method = $reflection->getMethod('verifyPassword');
        $method->setAccessible(true);

        $password = 'testpassword';
        $hash = '{MD5}' . md5($password);

        $result = $method->invoke($this->authService, $password, $hash);
        $this->assertTrue($result);
    }
}
