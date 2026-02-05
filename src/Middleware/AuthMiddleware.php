<?php

namespace Pfme\Api\Middleware;

use Pfme\Api\Services\TokenService;

/**
 * Authentication Middleware - validates JWT tokens
 */
class AuthMiddleware implements MiddlewareInterface
{
    private array $authUser = [];

    public function handle(): void
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader)) {
            $this->unauthorized('Missing authorization header');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $this->unauthorized('Invalid authorization header format');
        }

        $token = $matches[1];

        try {
            $tokenService = new TokenService();
            $payload = $tokenService->verifyAccessToken($token);

            // Store authenticated user info for injection
            $this->authUser = [
                'mailbox' => $payload->sub,
                'domain' => $payload->domain ?? null,
                'jti' => $payload->jti ?? null,
            ];
        } catch (\Exception $e) {
            $this->unauthorized($e->getMessage());
        }
    }

    public function getAuthenticatedUser(): array
    {
        return $this->authUser;
    }

    private function unauthorized(string $message): void
    {
        http_response_code(401);
        echo json_encode([
            'code' => 'unauthorized',
            'message' => $message,
        ]);
        exit;
    }
}
