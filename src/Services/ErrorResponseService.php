<?php

namespace Pfme\Api\Services;

/**
 * Error Response Service
 * 
 * Handles error response formatting based on deployment stage.
 * - development/qa/lab: Returns full exception details for debugging
 * - production: Returns generic messages for security
 */
class ErrorResponseService
{
    private string $deploymentStage;

    public function __construct()
    {
        $this->deploymentStage = strtolower(getenv('DEPLOYMENT_STAGE') ?: 'production');
    }

    /**
     * Get error message based on deployment stage
     * 
     * @param \Exception $e The exception to extract message from
     * @return string Error message appropriate for current stage
     */
    public function getErrorMessage(\Exception $e): string
    {
        if ($this->isDevStage()) {
            return $e->getMessage();  // Full details in dev stages
        }
        return 'An error occurred'; // Generic in production
    }

    /**
     * Get error response array with optional details based on stage
     * 
     * @param \Exception $e The exception
     * @param string $code The error code
     * @param int $statusCode HTTP status code
     * @return array Error response array
     */
    public function getErrorResponse(\Exception $e, string $code, int $statusCode): array
    {
        $response = [
            'code' => $code,
            'message' => $this->getErrorMessage($e),
            'status' => $statusCode,
        ];

        // Add extended debugging information in development stages
        if ($this->isDevStage()) {
            $response['details'] = [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->getFormattedTrace($e),
            ];
        }

        return $response;
    }

    /**
     * Get formatted stack trace
     * 
     * @param \Exception $e The exception
     * @return array Formatted trace array
     */
    private function getFormattedTrace(\Exception $e): array
    {
        $trace = [];
        foreach ($e->getTrace() as $frame) {
            $trace[] = [
                'file' => $frame['file'] ?? 'unknown',
                'line' => $frame['line'] ?? 0,
                'function' => $frame['function'] ?? 'unknown',
                'class' => $frame['class'] ?? null,
            ];
        }
        return $trace;
    }

    /**
     * Check if running in development/test stage
     * 
     * @return bool True if in development, qa, lab, or testing stage
     */
    private function isDevStage(): bool
    {
        return in_array($this->deploymentStage, [
            'development',
            'dev',
            'qa',
            'lab', 
            'testing',
            'test',
        ]);
    }

    /**
     * Get current deployment stage
     * 
     * @return string The deployment stage
     */
    public function getDeploymentStage(): string
    {
        return $this->deploymentStage;
    }
}
