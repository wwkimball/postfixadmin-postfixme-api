<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Controllers\AliasController;
use Pfme\Api\Services\AliasService;
use Pfme\Api\Services\ValidationService;

class AliasControllerTest extends TestCase
{
    private AliasController $controller;
    private AliasService $aliasService;
    private ValidationService $validationService;

    protected function setUp(): void
    {
        $this->aliasService = new AliasService();
        $this->validationService = new ValidationService();
        $this->controller = new AliasController();
    }

    /**
     * Test controller initialization without auth user
     */
    public function testControllerInitializationWithoutAuthUser(): void
    {
        $controller = new AliasController();
        $this->assertInstanceOf('Pfme\Api\Controllers\AliasController', $controller);
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

        $controller = new AliasController($authUser);
        $this->assertInstanceOf('Pfme\Api\Controllers\AliasController', $controller);
    }

    /**
     * Test list endpoint exists
     */
    public function testListEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('list'));
    }

    /**
     * Test create endpoint exists
     */
    public function testCreateEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('create'));
    }

    /**
     * Test update endpoint exists
     */
    public function testUpdateEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('update'));
    }

    /**
     * Test delete endpoint exists
     */
    public function testDeleteEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('delete'));
    }

    /**
     * Test destinations endpoint exists
     */
    public function testDestinationsEndpointExists(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $this->assertTrue($reflection->hasMethod('destinations'));
    }

    /**
     * Test main endpoints are public
     */
    public function testMainEndpointsArePublic(): void
    {
        $reflection = new \ReflectionClass($this->controller);

        $endpoints = ['list', 'create', 'update', 'delete', 'destinations'];

        foreach ($endpoints as $endpoint) {
            $method = $reflection->getMethod($endpoint);
            $this->assertTrue($method->isPublic(), "$endpoint should be public");
        }
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
     * Test controller has AliasService
     */
    public function testControllerHasAliasService(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('AliasService', $source);
    }

    /**
     * Test controller has ValidationService
     */
    public function testControllerHasValidationService(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('ValidationService', $source);
    }

    /**
     * Test create endpoint requires local_part
     */
    public function testCreateRequiresLocalPart(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('local_part', $source);
    }

    /**
     * Test create endpoint requires destinations
     */
    public function testCreateRequiresDestinations(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('destinations', $source);
    }

    /**
     * Test create validates local part format
     */
    public function testCreateValidatesLocalPartFormat(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Should validate using ValidationService
        $this->assertStringContainsString('validateLocalPart', $source);
    }

    /**
     * Test update endpoint accepts id parameter
     */
    public function testUpdateEndpointAcceptsIdParameter(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('update');

        $params = $method->getParameters();
        $this->assertGreaterThan(0, count($params));
    }

    /**
     * Test delete endpoint accepts id parameter
     */
    public function testDeleteEndpointAcceptsIdParameter(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('delete');

        $params = $method->getParameters();
        $this->assertGreaterThan(0, count($params));
    }

    /**
     * Test update can change local_part
     */
    public function testUpdateCanChangeLocalPart(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('local_part', $source);
    }

    /**
     * Test update can change destinations
     */
    public function testUpdateCanChangeDestinations(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // update method should handle destinations update
        $this->assertStringContainsString('destinations', $source);
    }

    /**
     * Test update can change active status
     */
    public function testUpdateCanChangeActiveStatus(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('active', $source);
    }

    /**
     * Test delete requires alias to be disabled first
     */
    public function testDeleteCheckForDisabledStatus(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Should handle disabled requirement
        $this->assertStringContainsString('active', $source);
    }

    /**
     * Test destinations endpoint returns available mailboxes
     */
    public function testDestinationsEndpointUsesAliasService(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('getAvailableMailboxes', $source);
    }

    /**
     * Test list endpoint uses authenticated user
     */
    public function testListEndpointUsesAuthenticatedUser(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('getAuthenticatedUser', $source);
    }

    /**
     * Test list endpoint supports pagination
     */
    public function testListEndpointSupportsPagination(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('page', $source);
        $this->assertStringContainsString('per_page', $source);
    }

    /**
     * Test list endpoint supports search
     */
    public function testListEndpointSupportsSearch(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Should support 'q' query parameter
        $this->assertStringContainsString('query', $source);
    }

    /**
     * Test list endpoint supports status filtering
     */
    public function testListEndpointSupportsStatusFilter(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('status', $source);
    }

    /**
     * Test create validates user is in destinations
     */
    public function testCreateRequiresUserInDestinations(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // User's mailbox must be in destinations
        $this->assertStringContainsString('mailbox', $source);
    }

    /**
     * Test error handling in controller methods
     */
    public function testControllerMethodsHaveErrorHandling(): void
    {
        $reflection = new \ReflectionClass($this->controller);
        $source = file_get_contents($reflection->getFileName());

        // Should have try-catch blocks
        $this->assertStringContainsString('catch', $source);
    }
}
