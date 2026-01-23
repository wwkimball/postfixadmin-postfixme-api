<?php

namespace Pfme\Api\Controllers;

use Pfme\Api\Services\AuthService;
use Pfme\Api\Services\TokenService;

/**
 * Authentication Controller
 */
class AuthController extends BaseController
{
    private AuthService $authService;
    private TokenService $tokenService;

    public function __construct()
    {
        parent::__construct();
        $this->authService = new AuthService();
        $this->tokenService = new TokenService();
    }

    public function login(): void
    {
        $input = $this->getJsonInput();

        // Validate input
        if (empty($input['mailbox']) || empty($input['password'])) {
            $this->error('mailbox and password are required', 400, 'invalid_input');
        }

        try {
            // Authenticate
            $isValid = $this->authService->authenticateMailbox(
                $input['mailbox'],
                $input['password']
            );

            if (!$isValid) {
                $this->error('Invalid credentials', 401, 'invalid_credentials');
            }

            // Get domain
            $domain = $this->authService->getDomainFromMailbox($input['mailbox']);

            // Generate tokens
            $accessToken = $this->tokenService->createAccessToken($input['mailbox'], $domain);
            $refreshTokenData = $this->tokenService->createRefreshToken(
                $input['mailbox'],
                $input['device_id'] ?? null
            );

            $this->success([
                'access_token' => $accessToken,
                'refresh_token' => $refreshTokenData['token'],
                'token_type' => 'Bearer',
                'expires_in' => $this->config['jwt']['access_token_ttl'],
                'user' => [
                    'mailbox' => $input['mailbox'],
                    'domain' => $domain,
                ],
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 401, 'authentication_failed');
        }
    }

    public function logout(): void
    {
        $user = $this->getAuthenticatedUser();

        // Revoke the current access token
        if (!empty($user['jti'])) {
            $this->tokenService->revokeAccessToken($user['jti']);
        }

        $this->success(['message' => 'Logged out successfully']);
    }

    public function refresh(): void
    {
        $input = $this->getJsonInput();

        if (empty($input['refresh_token'])) {
            $this->error('refresh_token is required', 400, 'invalid_input');
        }

        // Verify refresh token
        $tokenData = $this->tokenService->verifyRefreshToken($input['refresh_token']);

        if (!$tokenData) {
            $this->error('Invalid or expired refresh token', 401, 'invalid_token');
        }

        // Revoke old refresh token
        $this->tokenService->revokeRefreshToken($input['refresh_token']);

        // Get domain
        $domain = $this->authService->getDomainFromMailbox($tokenData['mailbox']);

        // Generate new tokens
        $accessToken = $this->tokenService->createAccessToken($tokenData['mailbox'], $domain);
        $newRefreshToken = $this->tokenService->createRefreshToken(
            $tokenData['mailbox'],
            $tokenData['device_id']
        );

        $this->success([
            'access_token' => $accessToken,
            'refresh_token' => $newRefreshToken['token'],
            'token_type' => 'Bearer',
            'expires_in' => $this->config['jwt']['access_token_ttl'],
        ]);
    }
}
