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

    public function __construct(?array $authUser = null)
    {
        parent::__construct($authUser);
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
                $input['mailbox']
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
            $this->exceptionError($e, 401, 'authentication_failed');
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

        try {
            // Verify refresh token
            $tokenData = $this->tokenService->verifyRefreshToken($input['refresh_token']);

            if (!$tokenData) {
                $this->error('Invalid or expired refresh token', 401, 'invalid_token');
            }

            // Check rate limiting per mailbox (SEC-009)
            if ($this->authService->isRateLimitedOnRefresh($tokenData['mailbox'])) {
                $this->authService->recordFailedRefreshAttempt($tokenData['mailbox']);
                $this->error('Too many refresh attempts. Please try again later.', 429, 'rate_limit_exceeded');
            }

            // Get domain
            $domain = $this->authService->getDomainFromMailbox($tokenData['mailbox']);

            // Rotate refresh token (with grace period support)
            $newRefreshToken = $this->tokenService->rotateRefreshToken(
                $input['refresh_token'],
                $tokenData['mailbox']
            );

            // Generate new access token
            $accessToken = $this->tokenService->createAccessToken($tokenData['mailbox'], $domain);

            // Record successful refresh attempt
            $this->authService->recordSuccessfulRefreshAttempt($tokenData['mailbox']);

            $this->success([
                'access_token' => $accessToken,
                'refresh_token' => $newRefreshToken['token'],
                'token_type' => 'Bearer',
                'expires_in' => $this->config['jwt']['access_token_ttl'],
            ]);
        } catch (\Exception $e) {
            // Record failed refresh attempt
            if (isset($tokenData['mailbox']) && !empty($tokenData['mailbox'])) {
                $this->authService->recordFailedRefreshAttempt($tokenData['mailbox']);
            }
            $this->exceptionError($e, 401, 'token_refresh_failed');
        }
    }

    public function changePassword(): void
    {
        $user = $this->getAuthenticatedUser();
        $input = $this->getJsonInput();

        if (empty($user['mailbox'])) {
            $this->error('Unauthorized', 401, 'unauthorized');
        }

        if (empty($input['current_password']) || empty($input['new_password'])) {
            $this->error('current_password and new_password are required', 400, 'invalid_input');
        }

        try {
            $this->authService->changeMailboxPassword(
                $user['mailbox'],
                $input['current_password'],
                $input['new_password']
            );

            $this->tokenService->revokeAllTokensForMailbox(
                $user['mailbox'],
                $user['jti'] ?? null
            );

            http_response_code(204);
            exit;
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400, 'invalid_password');
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 401) {
                $this->error('Current password is incorrect', 401, 'invalid_current_password');
            }

            $this->exceptionError($e, 400, 'change_password_failed');
        } catch (\Exception $e) {
            $this->exceptionError($e, 400, 'change_password_failed');
        }
    }

    public function health(): void
    {
        $this->success([
            'status' => 'ok',
            'timestamp' => time(),
        ]);
    }
}
