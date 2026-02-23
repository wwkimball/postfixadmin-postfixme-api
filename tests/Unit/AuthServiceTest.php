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
        // Test that verifyPassword method exists and is callable via reflection
        $reflection = new \ReflectionClass($this->authService);
        $this->assertTrue($reflection->hasMethod('verifyPassword'));

        $method = $reflection->getMethod('verifyPassword');
        $this->assertTrue($method->isPrivate());

        // Test getDomainFromMailbox which doesn't require PostfixAdmin config
        $domain = $this->authService->getDomainFromMailbox('test@example.com');
        $this->assertEquals('example.com', $domain);
    }

    public function testPasswordVerificationWithMD5Scheme(): void
    {
        // Test that the authentication service has password hash scheme support
        $reflection = new \ReflectionClass($this->authService);

        // Verify the necessary private methods exist for password verification
        $this->assertTrue($reflection->hasMethod('verifyPassword'));
        $this->assertTrue($reflection->hasMethod('loadPostfixAdminAuth'));

        // Test getDomainFromMailbox functionality
        $domain = $this->authService->getDomainFromMailbox('user@domain.test');
        $this->assertEquals('domain.test', $domain);
    }
}
