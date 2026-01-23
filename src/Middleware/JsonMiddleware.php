<?php

namespace Pfme\Api\Middleware;

/**
 * JSON Middleware - ensures all responses are JSON
 */
class JsonMiddleware implements MiddlewareInterface
{
    public function handle(): void
    {
        header('Content-Type: application/json');
    }
}
