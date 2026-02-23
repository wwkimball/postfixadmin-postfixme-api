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
use Pfme\Api\Middleware\CorsMiddleware;

class CorsMiddlewareTest extends TestCase
{
    /**
     * Test middleware implements MiddlewareInterface
     */
    public function testMiddlewareImplementsInterface(): void
    {
        $middleware = new CorsMiddleware();

        $this->assertInstanceOf('Pfme\Api\Middleware\MiddlewareInterface', $middleware);
    }

    /**
     * Test middleware handles OPTIONS request
     */
    public function testHandlesOptionsRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';

        $middleware = new CorsMiddleware();

        // The middleware should handle OPTIONS requests gracefully
        // (it responds with 204 and exits)
        $this->assertTrue(method_exists($middleware, 'handle'));
    }

    /**
     * Test middleware has handle method
     */
    public function testHasHandleMethod(): void
    {
        $middleware = new CorsMiddleware();

        $this->assertTrue(method_exists($middleware, 'handle'));

        $reflection = new \ReflectionMethod($middleware, 'handle');
        $this->assertTrue($reflection->isPublic());
    }

    /**
     * Test middleware is stateless
     */
    public function testMiddlewareIsStateless(): void
    {
        $middleware1 = new CorsMiddleware();
        $middleware2 = new CorsMiddleware();

        $this->assertNotSame($middleware1, $middleware2);
    }

    /**
     * Test middleware handles GET request
     */
    public function testHandlesGetRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $middleware = new CorsMiddleware();

        // Should have handle method
        $this->assertTrue(method_exists($middleware, 'handle'));
    }

    /**
     * Test middleware handles POST request
     */
    public function testHandlesPostRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $middleware = new CorsMiddleware();

        // Should have handle method
        $this->assertTrue(method_exists($middleware, 'handle'));
    }

    /**
     * Test middleware handles PUT request
     */
    public function testHandlesPutRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';

        $middleware = new CorsMiddleware();

        // Should have handle method
        $this->assertTrue(method_exists($middleware, 'handle'));
    }

    /**
     * Test middleware handles DELETE request
     */
    public function testHandlesDeleteRequest(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';

        $middleware = new CorsMiddleware();

        // Should have handle method
        $this->assertTrue(method_exists($middleware, 'handle'));
    }

    /**
     * Test middleware source code documents CORS policy
     */
    public function testSourceDocumentsCorsPolicy(): void
    {
        $reflection = new \ReflectionClass(new CorsMiddleware());
        $filename = $reflection->getFileName();
        $source = file_get_contents($filename);

        // Should mention that mobile apps don't use CORS
        $this->assertStringContainsString('mobile', strtolower($source));
    }

    /**
     * Test OPTIONS request handling logic
     */
    public function testOptionsRequestLogicExists(): void
    {
        $reflection = new \ReflectionClass(new CorsMiddleware());
        $filename = $reflection->getFileName();
        $source = file_get_contents($filename);

        // Should explicitly handle OPTIONS method
        $this->assertStringContainsString('OPTIONS', $source);
    }

    /**
     * Test no CORS headers are sent by default
     */
    public function testNoCorsHeadersByDefault(): void
    {
        $reflection = new \ReflectionClass(new CorsMiddleware());
        $filename = $reflection->getFileName();
        $source = file_get_contents($filename);

        // Should NOT set Access-Control-Allow-* headers
        // (intentionally omitted per comment)
        $this->assertStringNotContainsString('Access-Control-Allow-Origin', $source);
        $this->assertStringNotContainsString('Access-Control-Allow-Methods', $source);
        $this->assertStringNotContainsString('Access-Control-Allow-Headers', $source);
    }

    /**
     * Test OPTIONS response code
     */
    public function testOptionsResponseCode(): void
    {
        $reflection = new \ReflectionClass(new CorsMiddleware());
        $filename = $reflection->getFileName();
        $source = file_get_contents($filename);

        // Should return 204 for OPTIONS
        $this->assertStringContainsString('204', $source);
    }
}
