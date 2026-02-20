<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\ErrorResponseService;

class ErrorResponseServiceTest extends TestCase
{
    private ErrorResponseService $errorResponseService;

    protected function setUp(): void
    {
        $this->errorResponseService = new ErrorResponseService();
    }

    /**
     * Test error message redaction in production stage
     */
    public function testGetErrorMessageRedactsInProduction(): void
    {
        // Set to production stage
        putenv('DEPLOYMENT_STAGE=production');
        $service = new ErrorResponseService();

        $exception = new \Exception('Database connection failed with password: secret123');
        $message = $service->getErrorMessage($exception);

        $this->assertEquals('An error occurred', $message);
    }

    /**
     * Test error message includes details in development stage
     */
    public function testGetErrorMessageIncludesDetailsInDevelopment(): void
    {
        putenv('DEPLOYMENT_STAGE=development');
        $service = new ErrorResponseService();

        $exception = new \Exception('Database connection failed');
        $message = $service->getErrorMessage($exception);

        // In development, should include the actual exception message
        $this->assertStringContainsString('Database connection failed', $message);
    }

    /**
     * Test error message includes details in qa stage
     */
    public function testGetErrorMessageIncludesDetailsInQaStage(): void
    {
        putenv('DEPLOYMENT_STAGE=qa');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test error message');
        $message = $service->getErrorMessage($exception);

        $this->assertStringContainsString('Test error message', $message);
    }

    /**
     * Test error message includes details in lab stage
     */
    public function testGetErrorMessageIncludesDetailsInLabStage(): void
    {
        putenv('DEPLOYMENT_STAGE=lab');
        $service = new ErrorResponseService();

        $exception = new \Exception('Lab test message');
        $message = $service->getErrorMessage($exception);

        $this->assertStringContainsString('Lab test message', $message);
    }

    /**
     * Test error response structure in production
     */
    public function testGetErrorResponseStructureProduction(): void
    {
        putenv('DEPLOYMENT_STAGE=production');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test error');
        $response = $service->getErrorResponse($exception, 'test_code', 400);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('code', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('status', $response);
        $this->assertEquals('test_code', $response['code']);
        $this->assertEquals('An error occurred', $response['message']);
        $this->assertEquals(400, $response['status']);
    }

    /**
     * Test error response includes debug details in development
     */
    public function testGetErrorResponseIncludesDetailsInDevelopment(): void
    {
        putenv('DEPLOYMENT_STAGE=development');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test error');
        $response = $service->getErrorResponse($exception, 'test_code', 400);

        $this->assertArrayHasKey('details', $response);
        $this->assertArrayHasKey('exception', $response['details']);
        $this->assertArrayHasKey('file', $response['details']);
        $this->assertArrayHasKey('line', $response['details']);
        $this->assertArrayHasKey('trace', $response['details']);
    }

    /**
     * Test error response does not include details in production
     */
    public function testGetErrorResponseOmitsDetailsInProduction(): void
    {
        putenv('DEPLOYMENT_STAGE=production');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test error');
        $response = $service->getErrorResponse($exception, 'test_code', 400);

        $this->assertArrayNotHasKey('details', $response);
    }

    /**
     * Test exception class included in dev response
     */
    public function testExceptionClassIncludedInDevDetails(): void
    {
        putenv('DEPLOYMENT_STAGE=development');
        $service = new ErrorResponseService();

        $exception = new \RuntimeException('Test');
        $response = $service->getErrorResponse($exception, 'code', 400);

        $this->assertEquals('RuntimeException', $response['details']['exception']);
    }

    /**
     * Test file and line included in dev response
     */
    public function testFileAndLineIncludedInDevDetails(): void
    {
        putenv('DEPLOYMENT_STAGE=development');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test');
        $response = $service->getErrorResponse($exception, 'code', 400);

        $this->assertIsString($response['details']['file']);
        $this->assertIsInt($response['details']['line']);
        $this->assertGreaterThan(0, $response['details']['line']);
    }

    /**
     * Test stack trace is array in dev response
     */
    public function testStackTraceIsArrayInDevDetails(): void
    {
        putenv('DEPLOYMENT_STAGE=development');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test');
        $response = $service->getErrorResponse($exception, 'code', 400);

        $this->assertIsArray($response['details']['trace']);
    }

    /**
     * Test deployment stage getter
     */
    public function testGetDeploymentStageReturnsCurrentStage(): void
    {
        putenv('DEPLOYMENT_STAGE=staging');
        $service = new ErrorResponseService();

        $this->assertEquals('staging', $service->getDeploymentStage());
    }

    /**
     * Test default stage is production
     */
    public function testDefaultDeploymentStageIsProduction(): void
    {
        // Clear the env var
        putenv('DEPLOYMENT_STAGE=');
        $service = new ErrorResponseService();

        $this->assertEquals('production', $service->getDeploymentStage());
    }

    /**
     * Test safe trace string in production
     */
    public function testGetSafeTraceStringRedactsInProduction(): void
    {
        putenv('DEPLOYMENT_STAGE=production');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test');
        $trace = $service->getSafeTraceString($exception);

        // Should be a string
        $this->assertIsString($trace);
    }

    /**
     * Test safe trace string in development
     */
    public function testGetSafeTraceStringIncludesFullTraceInDevelopment(): void
    {
        putenv('DEPLOYMENT_STAGE=development');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test');
        $trace = $service->getSafeTraceString($exception);

        // Should be a string and contain trace
        $this->assertIsString($trace);
        $this->assertNotEmpty($trace);
    }

    /**
     * Test case-insensitive stage matching
     */
    public function testStageMappingIsCaseInsensitive(): void
    {
        putenv('DEPLOYMENT_STAGE=PRODUCTION');
        $service = new ErrorResponseService();

        // Convert to lowercase internally
        $this->assertEquals('production', $service->getDeploymentStage());
    }

    /**
     * Test trace includes file information
     */
    public function testFormattedTraceIncludesFileInfo(): void
    {
        putenv('DEPLOYMENT_STAGE=development');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test');
        $response = $service->getErrorResponse($exception, 'code', 400);

        $trace = $response['details']['trace'];
        $this->assertIsArray($trace);
        if (!empty($trace)) {
            $first = $trace[0];
            $this->assertArrayHasKey('file', $first);
            $this->assertArrayHasKey('line', $first);
        }
    }

    /**
     * Test trace includes function information
     */
    public function testFormattedTraceIncludesFunctionInfo(): void
    {
        putenv('DEPLOYMENT_STAGE=development');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test');
        $response = $service->getErrorResponse($exception, 'code', 400);

        $trace = $response['details']['trace'];
        if (!empty($trace)) {
            $first = $trace[0];
            $this->assertArrayHasKey('function', $first);
        }
    }

    /**
     * Test error response with different status codes
     */
    public function testErrorResponseWithVariousStatusCodes(): void
    {
        putenv('DEPLOYMENT_STAGE=production');
        $service = new ErrorResponseService();

        $exception = new \Exception('Test');

        $response400 = $service->getErrorResponse($exception, 'code', 400);
        $this->assertEquals(400, $response400['status']);

        $response401 = $service->getErrorResponse($exception, 'code', 401);
        $this->assertEquals(401, $response401['status']);

        $response500 = $service->getErrorResponse($exception, 'code', 500);
        $this->assertEquals(500, $response500['status']);
    }
}
