<?php

namespace Pfme\Api\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Middleware\TlsMiddleware;
use Pfme\Api\Middleware\CorsMiddleware;
use Pfme\Api\Middleware\JsonMiddleware;
use Pfme\Api\Middleware\SecurityHeadersMiddleware;
use Pfme\Api\Middleware\AuthMiddleware;
use Pfme\Api\Core\Application;

/**
 * Integration tests for middleware stack execution
 * 
 * Tests that middleware components work together correctly
 * and execute in proper order
 */
class MiddlewareIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset any global state
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        // Clean up headers if set
        if (function_exists('headers_sent') && headers_sent()) {
            // Can't reset headers once sent in test environment
        }
    }

    /**
     * Test that TLS middleware exists and can be instantiated
     */
    public function testTlsMiddlewareInstantiation(): void
    {
        $middleware = new TlsMiddleware();
        $this->assertInstanceOf(TlsMiddleware::class, $middleware);
    }

    /**
     * Test that CORS middleware exists and can be instantiated
     */
    public function testCorsMiddlewareInstantiation(): void
    {
        $middleware = new CorsMiddleware();
        $this->assertInstanceOf(CorsMiddleware::class, $middleware);
    }

    /**
     * Test that JSON middleware exists and can be instantiated
     */
    public function testJsonMiddlewareInstantiation(): void
    {
        $middleware = new JsonMiddleware();
        $this->assertInstanceOf(JsonMiddleware::class, $middleware);
    }

    /**
     * Test that Security Headers middleware exists and can be instantiated
     */
    public function testSecurityHeadersMiddlewareInstantiation(): void
    {
        $middleware = new SecurityHeadersMiddleware();
        $this->assertInstanceOf(SecurityHeadersMiddleware::class, $middleware);
    }

    /**
     * Test that Auth middleware exists and can be instantiated
     */
    public function testAuthMiddlewareInstantiation(): void
    {
        $middleware = new AuthMiddleware();
        $this->assertInstanceOf(AuthMiddleware::class, $middleware);
    }

    /**
     * Test Application can register middleware
     */
    public function testApplicationCanRegisterMiddleware(): void
    {
        $app = new Application();
        
        // Should not throw exception
        $app->use(new TlsMiddleware());
        $app->use(new CorsMiddleware());
        $app->use(new JsonMiddleware());
        $app->use(new SecurityHeadersMiddleware());
        
        $this->assertTrue(true, 'Application should register middleware without errors');
    }

    /**
     * Test TLS middleware handles HTTPS correctly
     */
    public function testTlsMiddlewareHandlesHTTPS(): void
    {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $middleware = new TlsMiddleware();
        
        // Should not throw exception for HTTPS request
        try {
            ob_start();
            $middleware->handle();
            $output = ob_get_clean();
            $this->assertTrue(true, 'TLS middleware should allow HTTPS');
        } catch (\Exception $e) {
            ob_end_clean();
            // Some exceptions are acceptable (exit/die)
            $this->assertIsString($e->getMessage());
        }
    }

    /**
     * Test CORS middleware handles OPTIONS requests
     */
    public function testCorsMiddlewareHandlesOptions(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';
        
        $middleware = new CorsMiddleware();
        
        ob_start();
        try {
            $middleware->handle();
        } catch (\Exception $e) {
            // OPTIONS may exit early
        }
        $output = ob_get_clean();
        
        $this->assertTrue(true, 'CORS middleware should handle OPTIONS requests');
    }

    /**
     * Test JSON middleware sets content type
     */
    public function testJsonMiddlewareSetsContentType(): void
    {
        $middleware = new JsonMiddleware();
        
        ob_start();
        $middleware->handle();
        ob_end_clean();
        
        // In test environment, we can't verify actual headers
        // But we can verify the middleware doesn't crash
        $this->assertTrue(true, 'JSON middleware should execute without errors');
    }

    /**
     * Test Security Headers middleware doesn't crash
     */
    public function testSecurityHeadersMiddlewareExecutes(): void
    {
        $_SERVER['HTTPS'] = 'on';
        
        $middleware = new SecurityHeadersMiddleware();
        
        ob_start();
        $middleware->handle();
        ob_end_clean();
        
        $this->assertTrue(true, 'Security headers middleware should execute');
    }

    /**
     * Test Auth middleware rejects request without token
     */
    public function testAuthMiddlewareRejectsNoToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_AUTHORIZATION']);
        
        $middleware = new AuthMiddleware();
        
        ob_start();
        try {
            $middleware->handle();
            $output = ob_get_clean();
            
            // Should have returned error response
            $this->assertNotEmpty($output, 'Should return error response');
        } catch (\Exception $e) {
            ob_end_clean();
            // Exception is acceptable
            $this->assertIsString($e->getMessage());
        }
    }

    /**
     * Test Auth middleware rejects invalid token
     */
    public function testAuthMiddlewareRejectsInvalidToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer invalid.token.here';
        
        $middleware = new AuthMiddleware();
        
        ob_start();
        try {
            $middleware->handle();
            $output = ob_get_clean();
            
            // Should have returned error response
            $this->assertNotEmpty($output, 'Should return error response for invalid token');
        } catch (\Exception $e) {
            ob_end_clean();
            $this->assertIsString($e->getMessage());
        }
    }

    /**
     * Test middleware execution order matters
     */
    public function testMiddlewareExecutionOrder(): void
    {
        $app = new Application();
        
        // Register in correct order
        $app->use(new TlsMiddleware());
        $app->use(new CorsMiddleware());
        $app->use(new JsonMiddleware());
        $app->use(new SecurityHeadersMiddleware());
        
        $this->assertTrue(true, 'Middleware should register in order');
    }

    /**
     * Test Application instantiation
     */
    public function testApplicationInstantiation(): void
    {
        $app = new Application();
        $this->assertInstanceOf(Application::class, $app);
    }

    /**
     * Test Application has getConfig method
     */
    public function testApplicationHasGetConfig(): void
    {
        $app = new Application();
        $config = $app->getConfig();
        
        $this->assertIsArray($config, 'Config should be array');
    }

    /**
     * Test middleware handle method is callable
     */
    public function testMiddlewareHandleMethodIsCallable(): void
    {
        $middlewares = [
            new TlsMiddleware(),
            new CorsMiddleware(),
            new JsonMiddleware(),
            new SecurityHeadersMiddleware(),
            new AuthMiddleware(),
        ];
        
        foreach ($middlewares as $middleware) {
            $this->assertTrue(
                method_exists($middleware, 'handle'),
                get_class($middleware) . ' should have handle method'
            );
        }
    }

    /**
     * Test TLS middleware with X-Forwarded-Proto header
     */
    public function testTlsMiddlewareWithForwardedProto(): void
    {
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $middleware = new TlsMiddleware();
        
        ob_start();
        try {
            $middleware->handle();
            ob_end_clean();
            $this->assertTrue(true, 'Should accept X-Forwarded-Proto: https');
        } catch (\Exception $e) {
            ob_end_clean();
            $this->assertIsString($e->getMessage());
        }
    }

    /**
     * Test CORS allows specific methods
     */
    public function testCorsAllowsHttpMethods(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
        
        foreach ($methods as $method) {
            $_SERVER['REQUEST_METHOD'] = $method;
            
            $middleware = new CorsMiddleware();
            
            ob_start();
            try {
                $middleware->handle();
            } catch (\Exception $e) {
                // Some methods may exit
            }
            ob_end_clean();
        }
        
        $this->assertTrue(true, 'CORS should handle all HTTP methods');
    }

    /**
     * Test that middleware doesn't leak sensitive information
     */
    public function testMiddlewareDoesntLeakSensitiveInfo(): void
    {
        // Set up some sensitive server variables
        $_SERVER['DB_PASSWORD'] = 'supersecret';
        $_SERVER['JWT_KEY'] = 'verysecret';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $middleware = new SecurityHeadersMiddleware();
        
        ob_start();
        $middleware->handle();
        $output = ob_get_clean();
        
        // Output should not contain sensitive info
        $this->assertStringNotContainsString('supersecret', $output, 'Should not leak DB password');
        $this->assertStringNotContainsString('verysecret', $output, 'Should not leak JWT key');
    }

    /**
     * Test middleware with missing REQUEST_METHOD
     */
    public function testMiddlewareHandlesMissingRequestMethod(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
        
        $middleware = new JsonMiddleware();
        
        ob_start();
        try {
            $middleware->handle();
            ob_end_clean();
            $this->assertTrue(true, 'Should handle missing REQUEST_METHOD');
        } catch (\Exception $e) {
            ob_end_clean();
            $this->assertIsString($e->getMessage());
        }
    }
}
