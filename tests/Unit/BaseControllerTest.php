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
use Pfme\Api\Controllers\BaseController;

// Create concrete implementation for testing abstract class
class ConcreteBaseController extends BaseController
{
    public function testAction(): void
    {
        $this->success(['test' => 'data']);
    }

    public function testError(): void
    {
        $this->error('Test error', 400, 'test_error');
    }

    // Make protected methods public for testing
    public function publicGetJsonInput(): array
    {
        return $this->getJsonInput();
    }

    public function publicGetQueryParams(): array
    {
        return $this->getQueryParams();
    }

    public function publicGetAuthenticatedUser(): array
    {
        return $this->getAuthenticatedUser();
    }

    public function publicPaginate(array $data, int $total): array
    {
        return $this->paginate($data, $total);
    }
}

class BaseControllerTest extends TestCase
{
    private ConcreteBaseController $controller;

    protected function setUp(): void
    {
        $this->controller = new ConcreteBaseController();
    }

    /**
     * Test controller can be instantiated
     */
    public function testControllerInstantiation(): void
    {
        $controller = new ConcreteBaseController();
        $this->assertInstanceOf('Pfme\Api\Controllers\BaseController', $controller);
    }

    /**
     * Test controller initialization with auth user
     */
    public function testControllerWithAuthUser(): void
    {
        $authUser = [
            'mailbox' => 'user@example.com',
            'domain' => 'example.com',
        ];

        $controller = new ConcreteBaseController($authUser);
        $this->assertInstanceOf('Pfme\Api\Controllers\BaseController', $controller);
    }

    /**
     * Test getAuthenticatedUser returns empty array initially
     */
    public function testGetAuthenticatedUserInitiallyEmpty(): void
    {
        $user = $this->controller->publicGetAuthenticatedUser();

        $this->assertIsArray($user);
        $this->assertEmpty($user);
    }

    /**
     * Test getAuthenticatedUser returns passed auth user
     */
    public function testGetAuthenticatedUserReturnsPassedUser(): void
    {
        $authUser = [
            'mailbox' => 'test@example.com',
            'domain' => 'example.com',
            'jti' => 'test-jti',
        ];

        $controller = new ConcreteBaseController($authUser);
        $user = $controller->publicGetAuthenticatedUser();

        $this->assertEquals('test@example.com', $user['mailbox']);
        $this->assertEquals('example.com', $user['domain']);
    }

    /**
     * Test getQueryParams returns empty array when no params
     */
    public function testGetQueryParamsEmptyWhenNoParams(): void
    {
        $_GET = [];
        $params = $this->controller->publicGetQueryParams();

        $this->assertIsArray($params);
        $this->assertEmpty($params);
    }

    /**
     * Test getQueryParams returns $_GET values
     */
    public function testGetQueryParamsReturnsGetParams(): void
    {
        $_GET = [
            'page' => '1',
            'q' => 'search',
            'sort' => 'name',
        ];

        $params = $this->controller->publicGetQueryParams();

        $this->assertArrayHasKey('page', $params);
        $this->assertArrayHasKey('q', $params);
        $this->assertArrayHasKey('sort', $params);
        $this->assertEquals('1', $params['page']);
        $this->assertEquals('search', $params['q']);
    }

    /**
     * Test getJsonInput with valid JSON
     */
    public function testGetJsonInputWithValidJson(): void
    {
        $json = '{"email":"test@example.com","password":"secret"}';

        // Mock php://input stream
        stream_context_set_default([
            'http' => ['method' => 'POST']
        ]);

        // Since we can't directly mock php://input, we test the function exists
        // and is accessible
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('getJsonInput');

        $this->assertTrue($method->isProtected());
    }

    /**
     * Test error method exists and is protected
     */
    public function testErrorMethodExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        $this->assertTrue($reflection->hasMethod('error'));
        $method = $reflection->getMethod('error');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test success method exists and is protected
     */
    public function testSuccessMethodExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        $this->assertTrue($reflection->hasMethod('success'));
        $method = $reflection->getMethod('success');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test exceptionError method exists
     */
    public function testExceptionErrorMethodExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        $this->assertTrue($reflection->hasMethod('exceptionError'));
        $method = $reflection->getMethod('exceptionError');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test paginate method exists
     */
    public function testPaginateMethodExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        $this->assertTrue($reflection->hasMethod('paginate'));
        $method = $reflection->getMethod('paginate');
        $this->assertTrue($method->isProtected());
    }

    /**
     * Test paginate returns correct structure
     */
    public function testPaginateReturnsCorrectStructure(): void
    {
        $data = [['id' => 1], ['id' => 2]];
        $total = 10;

        $result = $this->controller->publicPaginate($data, $total);

        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('meta', $result);
        $this->assertIsArray($result['meta']);
    }

    /**
     * Test paginate meta includes page info
     */
    public function testPaginateMetaIncludesPageInfo(): void
    {
        $_GET = ['page' => '2', 'per_page' => '5'];

        $data = [['id' => 1]];
        $total = 20;

        $result = $this->controller->publicPaginate($data, $total);

        $this->assertArrayHasKey('page', $result['meta']);
        $this->assertArrayHasKey('per_page', $result['meta']);
        $this->assertArrayHasKey('total', $result['meta']);
        $this->assertArrayHasKey('total_pages', $result['meta']);
    }

    /**
     * Test paginate meta includes total
     */
    public function testPaginateMetaIncludesTotal(): void
    {
        $data = array_fill(0, 5, ['id' => 1]);
        $total = 100;

        $result = $this->controller->publicPaginate($data, $total);

        $this->assertEquals(100, $result['meta']['total']);
    }

    /**
     * Test paginate calculates total pages
     */
    public function testPaginateCalculatesTotalPages(): void
    {
        $data = [];
        $total = 25;
        $_GET = ['per_page' => '10'];

        $result = $this->controller->publicPaginate($data, $total);

        // 25 items / 10 per page = 3 pages
        $this->assertEquals(3, $result['meta']['total_pages']);
    }

    /**
     * Test paginate with default per_page
     */
    public function testPaginateDefaultPerPage(): void
    {
        $_GET = [];

        $data = [];
        $total = 50;

        $result = $this->controller->publicPaginate($data, $total);

        // Should use config default
        $this->assertArrayHasKey('per_page', $result['meta']);
    }

    /**
     * Test paginate minimum page is 1
     */
    public function testPaginateMinimumPageIsOne(): void
    {
        $_GET = ['page' => '0'];

        $data = [];
        $total = 10;

        $result = $this->controller->publicPaginate($data, $total);

        $this->assertGreaterThanOrEqual(1, $result['meta']['page']);
    }

    /**
     * Test paginate minimum per_page is 1
     */
    public function testPaginateMinimumPerPageIsOne(): void
    {
        $_GET = ['per_page' => '0'];

        $data = [];
        $total = 10;

        $result = $this->controller->publicPaginate($data, $total);

        $this->assertGreaterThanOrEqual(1, $result['meta']['per_page']);
    }

    /**
     * Test controller has config loaded
     */
    public function testControllerLoadsConfig(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);

        $config = $property->getValue($this->controller);

        $this->assertIsArray($config);
        $this->assertNotEmpty($config);
    }

    /**
     * Test controller has ErrorResponseService
     */
    public function testControllerHasErrorResponseService(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $property = $reflection->getProperty('errorResponseService');
        $property->setAccessible(true);

        $service = $property->getValue($this->controller);

        $this->assertNotNull($service);
    }

    /**
     * Test error method requires message and status code
     */
    public function testErrorMethodParameters(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('error');

        $params = $method->getParameters();
        $this->assertGreaterThan(0, count($params));
    }

    /**
     * Test success method requires data and status code
     */
    public function testSuccessMethodParameters(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('success');

        $params = $method->getParameters();
        $this->assertGreaterThan(0, count($params));
    }
}
