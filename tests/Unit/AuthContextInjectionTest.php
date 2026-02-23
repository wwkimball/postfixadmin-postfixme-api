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
use Pfme\Api\Core\Router;
use Pfme\Api\Middleware\AuthMiddleware;

class AuthContextInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/test';
    }

    public function testRouterInjectsAuthUserIntoController(): void
    {
        $router = new Router();

        $middleware = new TestAuthMiddleware([
            'mailbox' => 'user@example.com',
            'domain' => 'example.com',
            'jti' => 'test-jti',
        ]);

        $router->get('/test', [TestAuthController::class, 'handle'], [$middleware]);
        $router->dispatch();

        $this->assertSame('user@example.com', TestAuthController::$receivedAuthUser['mailbox'] ?? null);
        $this->assertSame('example.com', TestAuthController::$receivedAuthUser['domain'] ?? null);
        $this->assertSame('test-jti', TestAuthController::$receivedAuthUser['jti'] ?? null);
    }

    public function testRouterInjectsNullAuthUserWhenNoMiddleware(): void
    {
        $router = new Router();
        $router->get('/test', [TestAuthController::class, 'handle']);
        $router->dispatch();

        $this->assertSame([], TestAuthController::$receivedAuthUser ?? null);
    }
}

class TestAuthMiddleware extends AuthMiddleware
{
    private array $testAuthUser;

    public function __construct(array $testAuthUser)
    {
        $this->testAuthUser = $testAuthUser;
    }

    public function handle(): void
    {
        $reflection = new \ReflectionProperty(AuthMiddleware::class, 'authUser');
        $reflection->setAccessible(true);
        $reflection->setValue($this, $this->testAuthUser);
    }
}

class TestAuthController
{
    public static array $receivedAuthUser = [];

    public function __construct(?array $authUser = null)
    {
        self::$receivedAuthUser = $authUser ?? [];
    }

    public function handle(): void
    {
        // no-op
    }
}
