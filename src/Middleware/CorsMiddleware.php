<?php

namespace Pfme\Api\Middleware;

/**
 * CORS Middleware
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function handle(): void
    {
        // Set CORS headers for mobile app
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Access-Control-Max-Age: 86400');

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
