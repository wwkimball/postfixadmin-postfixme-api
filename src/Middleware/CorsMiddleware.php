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
