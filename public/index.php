<?php
/**
 * PostfixMe API Entry Point
 *
 * This file serves as the main entry point for the PostfixMe REST API.
 * It initializes the application, handles routing, and dispatches requests.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Pfme\Api\Core\Application;
use Pfme\Api\Core\Router;
use Pfme\Api\Middleware\CorsMiddleware;
use Pfme\Api\Middleware\JsonMiddleware;
use Pfme\Api\Middleware\SecurityHeadersMiddleware;
use Pfme\Api\Middleware\TlsMiddleware;
use Pfme\Api\Middleware\AuthMiddleware;
use Pfme\Api\Controllers\AuthController;
use Pfme\Api\Controllers\AliasController;

// Load environment configuration
$app = new Application();
$router = new Router();

// Global middleware
$app->use(new CorsMiddleware());
$app->use(new JsonMiddleware());
$app->use(new SecurityHeadersMiddleware());
$app->use(new TlsMiddleware());

// Health check endpoint (no auth required)
$router->get('/api/v1/health', [AuthController::class, 'health']);

// Public routes (no auth required)
$router->post('/api/v1/auth/login', [AuthController::class, 'login']);
$router->post('/api/v1/auth/refresh', [AuthController::class, 'refresh']);

// Protected routes (auth required)
$router->post('/api/v1/auth/logout', [AuthController::class, 'logout'], [new AuthMiddleware()]);

$router->get('/api/v1/aliases', [AliasController::class, 'list'], [new AuthMiddleware()]);
$router->get('/api/v1/destinations', [AliasController::class, 'destinations'], [new AuthMiddleware()]);
$router->post('/api/v1/aliases', [AliasController::class, 'create'], [new AuthMiddleware()]);
$router->put('/api/v1/aliases/{id}', [AliasController::class, 'update'], [new AuthMiddleware()]);
$router->delete('/api/v1/aliases/{id}', [AliasController::class, 'delete'], [new AuthMiddleware()]);

// Handle the request
$app->handleRequest($router);
