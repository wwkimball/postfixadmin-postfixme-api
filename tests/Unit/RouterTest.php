<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Core\Router;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    /**
     * Test router can register GET route
     */
    public function testCanRegisterGetRoute(): void
    {
        // Router should accept GET route registration
        $this->router->get('/test', ['TestController', 'test']);
        $this->assertTrue(true); // If we got here, registration succeeded
    }

    /**
     * Test router can register POST route
     */
    public function testCanRegisterPostRoute(): void
    {
        $this->router->post('/test', ['TestController', 'test']);
        $this->assertTrue(true);
    }

    /**
     * Test router can register PUT route
     */
    public function testCanRegisterPutRoute(): void
    {
        $this->router->put('/test', ['TestController', 'test']);
        $this->assertTrue(true);
    }

    /**
     * Test router can register DELETE route
     */
    public function testCanRegisterDeleteRoute(): void
    {
        $this->router->delete('/test', ['TestController', 'test']);
        $this->assertTrue(true);
    }

    /**
     * Test router can register routes with middleware
     */
    public function testCanRegisterRouteWithMiddleware(): void
    {
        $middleware = [];
        $this->router->post('/test', ['TestController', 'test'], $middleware);
        $this->assertTrue(true);
    }

    /**
     * Test route matching with exact path
     */
    public function testRouteMatchingExactPath(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/api/test', '/api/test');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test route matching with parameter
     */
    public function testRouteMatchingWithParameter(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/api/alias/{id}', '/api/alias/123');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('123', $result['id']);
    }

    /**
     * Test route matching with multiple parameters
     */
    public function testRouteMatchingWithMultipleParameters(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/api/{domain}/{id}', '/api/example.com/123');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('domain', $result);
        $this->assertArrayHasKey('id', $result);
        $this->assertEquals('example.com', $result['domain']);
        $this->assertEquals('123', $result['id']);
    }

    /**
     * Test route not matching wrong path
     */
    public function testRouteNotMatchingWrongPath(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/api/test', '/api/other');
        $this->assertFalse($result);
    }

    /**
     * Test route parameter extraction with alphanumeric
     */
    public function testParameterExtractionAlphanumeric(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/user/{username}', '/user/john123');
        $this->assertIsArray($result);
        $this->assertEquals('john123', $result['username']);
    }

    /**
     * Test route parameter extraction with dashes
     */
    public function testParameterExtractionWithDashes(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/alias/{address}', '/alias/user-name@example.com');
        $this->assertIsArray($result);
        $this->assertEquals('user-name@example.com', $result['address']);
    }

    /**
     * Test route with trailing slash mismatch
     */
    public function testRouteWithTrailingSlashMismatch(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/api/test', '/api/test/');
        $this->assertFalse($result);
    }

    /**
     * Test empty parameter path does not match
     */
    public function testEmptyParameterDoesNotMatch(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/user/{id}', '/user/');
        $this->assertFalse($result);
    }

    /**
     * Test parameter with slash is not matched
     */
    public function testParameterWithSlashNotMatched(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/user/{id}', '/user/123/extra');
        $this->assertFalse($result);
    }

    /**
     * Test complex path with multiple segments
     */
    public function testComplexPathMatching(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/api/v1/alias/{id}/details', '/api/v1/alias/123/details');
        $this->assertIsArray($result);
        $this->assertEquals('123', $result['id']);
    }

    /**
     * Test route with special characters in fixed part
     */
    public function testRouteWithSpecialCharacters(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        // Parameters can contain dots (for email addresses)
        $result = $method->invoke($this->router, '/alias/{address}', '/alias/test@example.com');
        $this->assertIsArray($result);
        $this->assertEquals('test@example.com', $result['address']);
    }

    /**
     * Test parameter names are preserved
     */
    public function testParameterNamesPreserved(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/user/{userId}/post/{postId}', '/user/42/post/99');
        $this->assertArrayHasKey('userId', $result);
        $this->assertArrayHasKey('postId', $result);
        $this->assertEquals('42', $result['userId']);
        $this->assertEquals('99', $result['postId']);
    }

    /**
     * Test exact matching of static routes
     */
    public function testExactStaticRouteMatching(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        // Exact match
        $result = $method->invoke($this->router, '/api/status', '/api/status');
        $this->assertIsArray($result);

        // Should not match partial
        $result = $method->invoke($this->router, '/api/status', '/api/status/detail');
        $this->assertFalse($result);
    }

    /**
     * Test router returns false for non-matching route
     */
    public function testMatchRouteReturnsFalseForNonMatch(): void
    {
        $reflection = new \ReflectionClass($this->router);
        $method = $reflection->getMethod('matchRoute');
        $method->setAccessible(true);

        $result = $method->invoke($this->router, '/api/users', '/api/posts');
        $this->assertFalse($result);
    }
}
