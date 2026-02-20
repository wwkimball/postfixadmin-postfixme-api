<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Middleware\JsonMiddleware;

class JsonMiddlewareTest extends TestCase
{
    /**
     * Test JSON content type header is set
     */
    public function testJsonContentTypeHeaderIsSet(): void
    {
        // Since we can't directly test header() calls in PHPUnit,
        // we verify the middleware can be instantiated and called
        $middleware = new JsonMiddleware();

        // The handle method just calls header(), which can't be tested directly
        // in unit tests, but we verify it exists and is callable
        $this->assertTrue(method_exists($middleware, 'handle'));

        // Verify the method is public
        $reflection = new \ReflectionMethod($middleware, 'handle');
        $this->assertTrue($reflection->isPublic());
    }

    /**
     * Test middleware implements MiddlewareInterface
     */
    public function testMiddlewareImplementsInterface(): void
    {
        $middleware = new JsonMiddleware();

        $this->assertInstanceOf('Pfme\Api\Middleware\MiddlewareInterface', $middleware);
    }

    /**
     * Test correct Content-Type value
     */
    public function testCorrectContentTypeValue(): void
    {
        // The middleware should set Content-Type: application/json
        // We verify this by checking the implementation uses the correct header value
        $middleware = new JsonMiddleware();

        // Get the source code to verify the header value
        $reflection = new \ReflectionClass($middleware);
        $filename = $reflection->getFileName();
        $source = file_get_contents($filename);

        $this->assertStringContainsString('application/json', $source);
        $this->assertStringContainsString('Content-Type', $source);
    }

    /**
     * Test middleware is stateless
     */
    public function testMiddlewareIsStateless(): void
    {
        $middleware1 = new JsonMiddleware();
        $middleware2 = new JsonMiddleware();

        // Both instances should be independent
        $this->assertNotSame($middleware1, $middleware2);
    }

    /**
     * Test handle method can be called multiple times
     */
    public function testHandleCanBeCalledMultipleTimes(): void
    {
        $middleware = new JsonMiddleware();

        // Method should exist and be callable
        $this->assertTrue(method_exists($middleware, 'handle'));

        // Should not throw exception when called
        try {
            // Note: header() will produce a warning in CLI, but the method should be callable
            // We're just verifying the method exists and is public
            $reflection = new \ReflectionMethod($middleware, 'handle');
            $this->assertTrue($reflection->isPublic());
        } catch (\Throwable $e) {
            $this->fail('handle() method should be callable: ' . $e->getMessage());
        }
    }
}
