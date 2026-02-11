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

    /**
     * Get safe stack trace string for logging
     * SEC-034 mitigation: Redacts sensitive arguments in production
     *
     * @param \Exception $e The exception
     * @return string Safe trace string (full in dev, sanitized in production)
     */
    public function getSafeTraceString(\Exception $e): string
    {
        // In development stages, return full trace for debugging
        if ($this->isDevStage()) {
            return $e->getTraceAsString();
        }

        // In production/staging, sanitize sensitive arguments
        $trace = $e->getTrace();
        $safeTrace = [];

        // Sensitive parameter names to redact
        $sensitiveParams = ['password', 'token', 'secret', 'apikey', 'api_key', 'auth', 'credential'];

        foreach ($trace as $index => $frame) {
            $file = $frame['file'] ?? '[internal function]';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? '';
            $class = isset($frame['class']) ? $frame['class'] . $frame['type'] : '';

            // Build function call with sanitized arguments
            $args = [];
            if (isset($frame['args']) && is_array($frame['args'])) {
                foreach ($frame['args'] as $argIndex => $arg) {
                    // Check if this argument position corresponds to a sensitive parameter
                    $shouldRedact = false;

                    // Redact if function name contains sensitive keywords
                    $lowerFunction = strtolower($function);
                    foreach ($sensitiveParams as $sensitive) {
                        if (strpos($lowerFunction, $sensitive) !== false && $argIndex > 0) {
                            $shouldRedact = true;
                            break;
                        }
                    }

                    if ($shouldRedact) {
                        $args[] = '[REDACTED]';
                    } else {
                        $args[] = $this->formatArgument($arg);
                    }
                }
            }

            $argsString = implode(', ', $args);
            $location = $line > 0 ? "$file($line)" : $file;
            $safeTrace[] = "#{$index} {$location}: {$class}{$function}({$argsString})";
        }

        return implode("\n", $safeTrace);
    }

    /**
     * Format an argument for safe logging
     *
     * @param mixed $arg The argument to format
     * @return string Formatted argument
     */
    private function formatArgument($arg): string
    {
        if (is_string($arg)) {
            return "'" . (strlen($arg) > 50 ? substr($arg, 0, 47) . '...' : $arg) . "'";
        }
        if (is_numeric($arg)) {
            return (string)$arg;
        }
        if (is_bool($arg)) {
            return $arg ? 'true' : 'false';
        }
        if (is_null($arg)) {
            return 'NULL';
        }
        if (is_array($arg)) {
            return 'Array';
        }
        if (is_object($arg)) {
            return 'Object(' . get_class($arg) . ')';
        }
        return 'Unknown';
    }
}
