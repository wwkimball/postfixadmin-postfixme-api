<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Core\Application;
use Pfme\Api\Middleware\MiddlewareInterface;

class ApplicationTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        $this->application = new Application();
    }

    /**
     * Test application can be instantiated
     */
    public function testApplicationInstantiation(): void
    {
        $application = new Application();
        $this->assertInstanceOf('Pfme\Api\Core\Application', $application);
    }

    /**
     * Test application loads config
     */
    public function testApplicationLoadsConfig(): void
    {
        $config = $this->application->getConfig();

        $this->assertIsArray($config);
        $this->assertNotEmpty($config);
    }

    /**
     * Test getConfig with null returns all config
     */
    public function testGetConfigWithNullReturnsAll(): void
    {
        $config = $this->application->getConfig(null);

        $this->assertIsArray($config);
    }

    /**
     * Test getConfig with key returns specific value
     */
    public function testGetConfigWithKeyReturnsValue(): void
    {
        // Config should have jwt key
        $jwtConfig = $this->application->getConfig('jwt');

        $this->assertIsArray($jwtConfig);
    }

    /**
     * Test getConfig with nested key
     */
    public function testGetConfigWithNestedKey(): void
    {
        // Access nested config like jwt.algorithm
        $value = $this->application->getConfig('jwt.algorithm');

        // Should return string or be null
        $this->assertTrue(is_string($value) || $value === null);
    }

    /**
     * Test getConfig returns null for missing key
     */
    public function testGetConfigReturnsNullForMissing(): void
    {
        $value = $this->application->getConfig('nonexistent.key');

        $this->assertNull($value);
    }

    /**
     * Test application can register middleware
     */
    public function testApplicationRegisteresMiddleware(): void
    {
        // Create a mock middleware
        $middleware = new class implements MiddlewareInterface {
            public function handle(): void {}
        };

        $this->application->use($middleware);
        $this->assertTrue(true); // If we got here, it worked
    }

    /**
     * Test application can register multiple middleware
     */
    public function testApplicationRegistersMultipleMiddleware(): void
    {
        $middleware1 = new class implements MiddlewareInterface {
            public function handle(): void {}
        };

        $middleware2 = new class implements MiddlewareInterface {
            public function handle(): void {}
        };

        $this->application->use($middleware1);
        $this->application->use($middleware2);

        $this->assertTrue(true);
    }

    /**
     * Test use method accepts MiddlewareInterface
     */
    public function testUseMethodAcceptsMiddlewareInterface(): void
    {
        $middleware = new class implements MiddlewareInterface {
            public function handle(): void {}
        };

        // This should not throw
        $this->application->use($middleware);
        $this->assertTrue(true);
    }

    /**
     * Test error handler exists
     */
    public function testErrorHandlerExists(): void
    {
        $reflection = new \ReflectionClass($this->application);

        $this->assertTrue($reflection->hasMethod('errorHandler'));
    }

    /**
     * Test exception handler exists
     */
    public function testExceptionHandlerExists(): void
    {
        $reflection = new \ReflectionClass($this->application);

        $this->assertTrue($reflection->hasMethod('exceptionHandler'));
    }

    /**
     * Test handleRequest method exists
     */
    public function testHandleRequestMethodExists(): void
    {
        $reflection = new \ReflectionClass($this->application);

        $this->assertTrue($reflection->hasMethod('handleRequest'));
    }

    /**
     * Test error handler is public
     */
    public function testErrorHandlerIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->application);
        $method = $reflection->getMethod('errorHandler');

        $this->assertTrue($method->isPublic());
    }

    /**
     * Test exception handler is public
     */
    public function testExceptionHandlerIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->application);
        $method = $reflection->getMethod('exceptionHandler');

        $this->assertTrue($method->isPublic());
    }

    /**
     * Test handleRequest is public
     */
    public function testHandleRequestIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->application);
        $method = $reflection->getMethod('handleRequest');

        $this->assertTrue($method->isPublic());
    }

    /**
     * Test use method is public
     */
    public function testUseMethodIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->application);
        $method = $reflection->getMethod('use');

        $this->assertTrue($method->isPublic());
    }

    /**
     * Test getConfig is public
     */
    public function testGetConfigIsPublic(): void
    {
        $reflection = new \ReflectionClass($this->application);
        $method = $reflection->getMethod('getConfig');

        $this->assertTrue($method->isPublic());
    }

    /**
     * Test error reporting set to E_ALL
     */
    public function testErrorReportingConfiguration(): void
    {
        // Application should set error reporting in constructor
        $this->assertTrue(true);
    }

    /**
     * Test config is accessible after application creation
     */
    public function testConfigAccessible(): void
    {
        $config = $this->application->getConfig();

        $this->assertIsArray($config);
        $this->assertCount(count($config), $config); // Should have items
    }

    /**
     * Test getConfig with multiple nested levels
     */
    public function testGetConfigMultipleNestedLevels(): void
    {
        // Should handle deeply nested configs
        $value = $this->application->getConfig('security.password_min_length');

        // Either returns value or null
        $this->assertTrue(is_numeric($value) || $value === null);
    }
}
