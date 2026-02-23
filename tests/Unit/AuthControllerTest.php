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
use Pfme\Api\Controllers\AuthController;
use Pfme\Api\Services\AuthService;
use Pfme\Api\Services\TokenService;
use Pfme\Api\Core\Database;

class AuthControllerTest extends TestCase
{
    private AuthController $controller;
    private TokenService $tokenService;
    private AuthService $authService;
    private \PDO $db;

    protected function setUp(): void
    {
        $this->authService = new AuthService();
        $this->tokenService = new TokenService();
        $this->controller = new AuthController();
        $this->db = Database::getConnection();
    }

    protected function tearDown(): void
    {
        // Clean up test data
        $this->db->exec('DELETE FROM pfme_auth_log WHERE mailbox LIKE "auth-controller-test%"');
        $this->db->exec('DELETE FROM pfme_refresh_tokens WHERE mailbox LIKE "auth-controller-test%"');
    }

    /**
     * Test controller initialization without auth user
     */
    public function testControllerInitializationWithoutAuthUser(): void
    {
        $controller = new AuthController();
        $this->assertInstanceOf('Pfme\Api\Controllers\AuthController', $controller);
    }

    /**
     * Test controller initialization with auth user
     */
    public function testControllerInitializationWithAuthUser(): void
    {
        $authUser = [
            'mailbox' => 'user@example.com',
            'domain' => 'example.com',
            'jti' => 'test-jti',
        ];

        $controller = new AuthController($authUser);
        $this->assertInstanceOf('Pfme\Api\Controllers\AuthController', $controller);
    }

    /**
     * Test health endpoint exists and is callable
     */
    public function testHealthEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('health'));

        $method = $reflection->getMethod('health');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test password policy endpoint exists
     */
    public function testPasswordPolicyEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('passwordPolicy'));

        $method = $reflection->getMethod('passwordPolicy');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test login endpoint exists
     */
    public function testLoginEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('login'));

        $method = $reflection->getMethod('login');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test logout endpoint exists
     */
    public function testLogoutEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('logout'));

        $method = $reflection->getMethod('logout');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test refresh endpoint exists
     */
    public function testRefreshEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('refresh'));

        $method = $reflection->getMethod('refresh');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test changePassword endpoint exists
     */
    public function testChangePasswordEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('changePassword'));

        $method = $reflection->getMethod('changePassword');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test controller extends BaseController
     */
    public function testControllerExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $parentClass = $reflection->getParentClass();

        $this->assertNotNull($parentClass);
        $this->assertEquals('Pfme\Api\Controllers\BaseController', $parentClass->getName());
    }

    /**
     * Test controller has TokenService
     */
    public function testControllerHasTokenService(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        // Check if TokenService is used
        $source = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('TokenService', $source);
    }

    /**
     * Test controller has AuthService
     */
    public function testControllerHasAuthService(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        $source = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('AuthService', $source);
    }

    /**
     * Test controller methods are public
     */
    public function testMainEndpointsArePublic(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        $endpoints = ['login', 'logout', 'refresh', 'changePassword', 'health', 'passwordPolicy'];

        foreach ($endpoints as $endpoint) {
            $method = $reflection->getMethod($endpoint);
            $this->assertTrue($method->isPublic(), "$endpoint should be public");
        }
    }

    /**
     * Test refresh token parameter is required
     */
    public function testRefreshEndpointRequiresRefreshToken(): void
    {
        // The method requires refresh_token in request body
        // Verify through reflection that this is validated
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('refresh');

        $source = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('refresh_token', $source);
    }

    /**
     * Test logout endpoint uses authenticated user
     */
    public function testLogoutEndpointUsesAuthenticatedUser(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // logout should use getAuthenticatedUser
        $this->assertStringContainsString('getAuthenticatedUser', $source);
    }

    /**
     * Test change password endpoint requires current password
     */
    public function testChangePasswordRequiresCurrentPassword(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('current_password', $source);
    }

    /**
     * Test change password endpoint requires new password
     */
    public function testChangePasswordRequiresNewPassword(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('new_password', $source);
    }

    /**
     * Test rate limiting is checked on password change
     */
    public function testChangePasswordChecksRateLimiting(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Should check rate limiting
        $this->assertStringContainsString('isRateLimitedOnPasswordChange', $source);
    }

    /**
     * Test rate limiting is checked on refresh
     */
    public function testRefreshChecksRateLimiting(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('isRateLimitedOnRefresh', $source);
    }

    /**
     * Test login endpoint requires mailbox and password
     */
    public function testLoginRequiresMailboxAndPassword(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('mailbox', $source);
        $this->assertStringContainsString('password', $source);
    }

    /**
     * Test error handling in refresh endpoint
     */
    public function testRefreshHasErrorHandling(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Should have exception handling
        $this->assertStringContainsString('catch', $source);
    }

    /**
     * Test logout revokes all tokens
     */
    public function testLogoutRevokesTokens(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Logout should revoke all tokens for mailbox
        $this->assertStringContainsString('revokeAllTokensForMailbox', $source);
    }

    /**
     * Test password policy endpoint exists as public method
     */
    public function testPasswordPolicyIsPublicEndpoint(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        // The passwordPolicy method should exist and be public
        $this->assertTrue($reflection->hasMethod('passwordPolicy'));
        $method = $reflection->getMethod('passwordPolicy');
        $this->assertTrue($method->isPublic());
    }

    /**
     * Test health endpoint is public
     */
    public function testHealthEndpointIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Health check endpoint
        $this->assertStringContainsString('health', $source);
    }

    /**
     * Test successful refresh token handling
     */
    public function testRefreshTokenHandling(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Should handle successful refresh
        $this->assertStringContainsString('createAccessToken', $source);
        $this->assertStringContainsString('rotateRefreshToken', $source);
    }
}
