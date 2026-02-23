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


namespace Pfme\Api\Core;

use Pfme\Api\Middleware\MiddlewareInterface;

/**
 * Simple router for API endpoints
 */
class Router
{
    private array $routes = [];

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, array $handler, array $middleware): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->matchRoute($route['path'], $uri);
            if ($params === false) {
                continue;
            }

            $authUser = null;

            // Apply route-specific middleware
            foreach ($route['middleware'] as $mw) {
                if ($mw instanceof MiddlewareInterface) {
                    $mw->handle();

                    if ($mw instanceof \Pfme\Api\Middleware\AuthMiddleware) {
                        $authUser = $mw->getAuthenticatedUser();
                    }
                }
            }

            // Call the handler
            $controller = new $route['handler'][0]($authUser);
            $method = $route['handler'][1];

            call_user_func_array([$controller, $method], $params);
            return;
        }

        // No route matched
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'code' => 'not_found',
            'message' => 'Endpoint not found',
        ]);
    }

    private function matchRoute(string $pattern, string $uri): array|false
    {
        // Convert {param} to regex capture groups
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $uri, $matches)) {
            // Extract named parameters
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return false;
    }
}
