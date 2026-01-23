<?php

namespace Pfme\Api\Core;

use Pfme\Api\Middleware\MiddlewareInterface;

/**
 * Main Application class
 */
class Application
{
    private array $middleware = [];
    private array $config;

    public function __construct()
    {
        $this->config = require __DIR__ . '/../../config/config.php';

        // Set error handling
        error_reporting(E_ALL);
        set_error_handler([$this, 'errorHandler']);
        set_exception_handler([$this, 'exceptionHandler']);
    }

    public function use(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function handleRequest(Router $router): void
    {
        try {
            // Apply global middleware
            foreach ($this->middleware as $mw) {
                $mw->handle();
            }

            // Route the request
            $router->dispatch();
        } catch (\Throwable $e) {
            $this->exceptionHandler($e);
        }
    }

    public function getConfig(string $key = null)
    {
        if ($key === null) {
            return $this->config;
        }

        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function errorHandler($severity, $message, $file, $line): void
    {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    }

    public function exceptionHandler(\Throwable $e): void
    {
        http_response_code(500);
        header('Content-Type: application/json');

        $response = [
            'code' => 'internal_error',
            'message' => 'An internal error occurred',
        ];

        // In development, include details
        if (getenv('APP_ENV') === 'development') {
            $response['details'] = [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ];
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
