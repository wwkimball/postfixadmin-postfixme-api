<?php

namespace Pfme\Api\Middleware;

/**
 * CORS Middleware
 *
 * NOTE: This API serves mobile clients only (iOS app).
 * Mobile apps do not use CORS—this is a browser-only mechanism.
 * CORS headers are intentionally omitted.
 *
 * If web browser access is added in the future, implement proper CORS
 * restrictions via environment variable. Do NOT use wildcard origin.
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function handle(): void
    {
        // Handle preflight requests (if any mobile SDK sends OPTIONS,
        // gracefully respond without unnecessary CORS headers)
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
}
