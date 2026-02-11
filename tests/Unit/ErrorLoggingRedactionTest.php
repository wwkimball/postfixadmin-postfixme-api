<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\ErrorResponseService;

/**
 * Test for SEC-034 mitigation: Stage-aware sensitive data redaction in error logs
 */
class ErrorLoggingRedactionTest extends TestCase
{
    private ErrorResponseService $errorService;

    protected function setUp(): void
    {
        $this->errorService = new ErrorResponseService();
    }

    /**
     * Test that development mode preserves full stack traces
     */
    public function testDevelopmentModePreservesFullTrace(): void
    {
        // Temporarily set deployment stage to development
        putenv('DEPLOYMENT_STAGE=development');
        $devService = new ErrorResponseService();

        $exception = $this->createExceptionWithSensitiveData();
        $safeTrace = $devService->getSafeTraceString($exception);

        // In development, trace should be identical to original
        $this->assertEquals($exception->getTraceAsString(), $safeTrace);

        // Restore original environment
        putenv('DEPLOYMENT_STAGE=' . getenv('DEPLOYMENT_STAGE'));
    }

    /**
     * Test that production mode redacts sensitive arguments
     */
    public function testProductionModeRedactsSensitiveArguments(): void
    {
        // Temporarily set deployment stage to production
        putenv('DEPLOYMENT_STAGE=production');
        $prodService = new ErrorResponseService();

        $exception = $this->createExceptionWithSensitiveData();
        $safeTrace = $prodService->getSafeTraceString($exception);

        // In production, sensitive arguments should be redacted
        $this->assertStringContainsString('[REDACTED]', $safeTrace);
        $this->assertStringNotContainsString('SuperSecret123!Password', $safeTrace);

        // Restore original environment
        putenv('DEPLOYMENT_STAGE=' . getenv('DEPLOYMENT_STAGE'));
    }

    /**
     * Test that QA mode preserves full traces (dev stage)
     */
    public function testQaModePreservesFullTrace(): void
    {
        putenv('DEPLOYMENT_STAGE=qa');
        $qaService = new ErrorResponseService();

        $exception = $this->createExceptionWithSensitiveData();
        $safeTrace = $qaService->getSafeTraceString($exception);

        // QA is a dev stage - should preserve full trace
        $this->assertEquals($exception->getTraceAsString(), $safeTrace);

        // Restore original environment
        putenv('DEPLOYMENT_STAGE=' . getenv('DEPLOYMENT_STAGE'));
    }

    /**
     * Test that non-sensitive functions are not redacted
     */
    public function testNonSensitiveFunctionsNotRedacted(): void
    {
        putenv('DEPLOYMENT_STAGE=production');
        $prodService = new ErrorResponseService();

        try {
            $this->nonSensitiveFunction('testuser@example.com', 42);
        } catch (\Exception $e) {
            $safeTrace = $prodService->getSafeTraceString($e);

            // Non-sensitive arguments should still appear
            $this->assertStringContainsString('testuser@example.com', $safeTrace);
            $this->assertStringContainsString('42', $safeTrace);
        }

        putenv('DEPLOYMENT_STAGE=' . getenv('DEPLOYMENT_STAGE'));
    }

    /**
     * Test getSafeTraceString handles exceptions without traces
     */
    public function testHandlesExceptionWithoutTrace(): void
    {
        $exception = new \Exception('Simple error');
        $safeTrace = $this->errorService->getSafeTraceString($exception);

        $this->assertIsString($safeTrace);
        $this->assertStringContainsString('Simple error', $exception->getMessage());
    }

    /**
     * Helper: Create an exception with sensitive data in the trace
     */
    private function createExceptionWithSensitiveData(): \Exception
    {
        try {
            $this->authenticateUser('testuser@example.com', 'SuperSecret123!Password');
        } catch (\Exception $e) {
            return $e;
        }

        throw new \Exception('Test failed to generate exception');
    }

    /**
     * Helper: Simulates a sensitive function that would expose password
     */
    private function authenticateUser(string $username, string $password): void
    {
        $this->changePassword($username, 'oldpass', $password);
    }

    /**
     * Helper: Another sensitive function
     */
    private function changePassword(string $user, string $oldPassword, string $newPassword): void
    {
        throw new \Exception("Database connection failed");
    }

    /**
     * Helper: Non-sensitive function for testing
     */
    private function nonSensitiveFunction(string $email, int $count): void
    {
        throw new \Exception("Non-sensitive error");
    }
}
