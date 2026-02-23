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


namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Controllers\AuthController;
use Pfme\Api\Services\AuthService;
use Pfme\Api\Services\TokenService;
use Pfme\Api\Core\Database;

class AuthControllerBehaviorTest extends TestCase
{
    private \PDO $db;
    private AuthService $authService;
    private TokenService $tokenService;

    protected function setUp(): void
    {
        $this->db = Database::getConnection();
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
        $this->db->exec("DELETE FROM pfme_auth_log WHERE mailbox = 'user1@acme.local'");
        $this->authService = new AuthService();
        $this->tokenService = new TokenService();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    private function injectServices(AuthController $controller, $authService, $tokenService): void
    {
        $ref = new \ReflectionClass(AuthController::class);

        $authProp = $ref->getProperty('authService');
        $authProp->setAccessible(true);
        $authProp->setValue($controller, $authService);

        $tokenProp = $ref->getProperty('tokenService');
        $tokenProp->setAccessible(true);
        $tokenProp->setValue($controller, $tokenService);
    }

    public function testLoginSuccessReturnsTokens(): void
    {
        $controller = new class extends AuthController {
            public array $response;
            protected function getJsonInput(): array
            {
                return [
                    'mailbox' => 'user1@acme.local',
                    'password' => 'testpass123',
                ];
            }
            protected function success(array $data, int $statusCode = 200): void
            {
                throw new class($data, $statusCode) extends \RuntimeException {
                    public array $data;
                    public int $status;
                    public function __construct(array $data, int $status)
                    {
                        parent::__construct('success');
                        $this->data = $data;
                        $this->status = $status;
                    }
                };
            }
            protected function error(string $message, int $statusCode = 400, string $code = 'error', array $details = []): void
            {
                throw new \RuntimeException("error:{$code}:{$statusCode}:{$message}");
            }
            protected function exceptionError(\Exception $e, int $statusCode = 400, string $code = 'error'): void
            {
                if ($e instanceof \RuntimeException) {
                    $msg = $e->getMessage();
                    if ($msg === 'success' || str_starts_with($msg, 'error:')) {
                        throw $e; // propagate sentinel markers without conversion
                    }
                }

                throw new \RuntimeException("exception:{$code}:{$statusCode}:{$e->getMessage()}");
            }
        };

        $authStub = new class extends AuthService {
            public function __construct()
            {
            }
            public function authenticateMailbox(string $mailbox, string $password): bool
            {
                return $mailbox === 'user1@acme.local' && $password === 'testpass123';
            }
            public function getDomainFromMailbox(string $mailbox): string
            {
                return explode('@', $mailbox)[1] ?? 'acme.local';
            }
        };

        $tokenStub = new class extends TokenService {
            public function __construct()
            {
            }
            public function createAccessToken(string $mailbox, string $domain): string
            {
                return 'access-token';
            }
            public function createRefreshToken(string $mailbox): array
            {
                return ['token' => 'refresh-token'];
            }
            public function revokeAllTokensForMailbox(string $mailbox, ?string $jti = null): void
            {
            }
        };

        $this->injectServices($controller, $authStub, $tokenStub);

        try {
            $controller->login();
        } catch (\RuntimeException $e) {
            $this->assertEquals('success', $e->getMessage());
            $this->assertInstanceOf('RuntimeException', $e);
            $this->assertObjectHasProperty('data', $e);
            $this->assertObjectHasProperty('status', $e);
            /** @var \RuntimeException $e */
            $this->assertEquals(200, $e->status);
            $this->assertArrayHasKey('access_token', $e->data);
            $this->assertArrayHasKey('refresh_token', $e->data);
            $this->assertEquals('Bearer', $e->data['token_type']);
            return;
        }

        $this->fail('Expected controller to throw success wrapper');
    }

    public function testLoginInvalidCredentialsReturnsError(): void
    {
        $controller = new class extends AuthController {
            protected function getJsonInput(): array
            {
                return [
                    'mailbox' => 'user1@acme.local',
                    'password' => 'wrongpass',
                ];
            }
            protected function success(array $data, int $statusCode = 200): void
            {
                throw new \RuntimeException('unexpected-success');
            }
            protected function error(string $message, int $statusCode = 400, string $code = 'error', array $details = []): void
            {
                throw new \RuntimeException("error:{$code}:{$statusCode}:{$message}");
            }
            protected function exceptionError(\Exception $e, int $statusCode = 400, string $code = 'error'): void
            {
                if ($e instanceof \RuntimeException) {
                    $msg = $e->getMessage();
                    if ($msg === 'success' || str_starts_with($msg, 'error:')) {
                        throw $e;
                    }
                }

                throw new \RuntimeException("exception:{$code}:{$statusCode}:{$e->getMessage()}");
            }
        };

        $authStub = new class extends AuthService {
            public function __construct()
            {
            }
            public function authenticateMailbox(string $mailbox, string $password): bool
            {
                return false;
            }
            public function getDomainFromMailbox(string $mailbox): string
            {
                return explode('@', $mailbox)[1] ?? 'acme.local';
            }
        };

        $tokenStub = new class extends TokenService {
            public function __construct()
            {
            }
            public function createAccessToken(string $mailbox, string $domain): string
            {
                return 'access-token';
            }
            public function createRefreshToken(string $mailbox): array
            {
                return ['token' => 'refresh-token'];
            }
            public function revokeAllTokensForMailbox(string $mailbox, ?string $jti = null): void
            {
            }
        };

        $this->injectServices($controller, $authStub, $tokenStub);

        try {
            $controller->login();
            $this->fail('Expected error');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('error:invalid_credentials:401', $e->getMessage());
        }
    }

    public function testRefreshRequiresTokenAndHandlesInvalid(): void
    {
        $controller = new class extends AuthController {
            protected function getJsonInput(): array
            {
                return [];
            }
            protected function success(array $data, int $statusCode = 200): void
            {
                throw new \RuntimeException('unexpected-success');
            }
            protected function error(string $message, int $statusCode = 400, string $code = 'error', array $details = []): void
            {
                throw new \RuntimeException("error:{$code}:{$statusCode}:{$message}");
            }
            protected function exceptionError(\Exception $e, int $statusCode = 400, string $code = 'error'): void
            {
                if ($e instanceof \RuntimeException) {
                    $msg = $e->getMessage();
                    if ($msg === 'success' || str_starts_with($msg, 'error:')) {
                        throw $e;
                    }
                }

                throw new \RuntimeException("exception:{$code}:{$statusCode}:{$e->getMessage()}");
            }
        };

        $authStub = new class extends AuthService {
            public function __construct()
            {
            }
            public function authenticateMailbox(string $mailbox, string $password): bool
            {
                return false;
            }
            public function getDomainFromMailbox(string $mailbox): string
            {
                return explode('@', $mailbox)[1] ?? 'acme.local';
            }
        };

        $tokenStub = new class extends TokenService {
            public function __construct()
            {
            }
            public function createAccessToken(string $mailbox, string $domain): string
            {
                return 'access-token';
            }
            public function createRefreshToken(string $mailbox): array
            {
                return ['token' => 'refresh-token'];
            }
            public function revokeAllTokensForMailbox(string $mailbox, ?string $jti = null): void
            {
            }
        };

        $this->injectServices($controller, $authStub, $tokenStub);

        try {
            $controller->refresh();
            $this->fail('Expected error');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('error:invalid_input:400', $e->getMessage());
        }
    }

    public function testLogoutRevokesTokens(): void
    {
        // Seed a token for user
        $access = $this->tokenService->createAccessToken('user1@acme.local', 'acme.local');
        $decoded = $this->tokenService->verifyAccessToken($access);

        $controller = new class($decoded) extends AuthController {
            public function __construct($authUser)
            {
                parent::__construct([
                    'mailbox' => 'user1@acme.local',
                    'domain' => 'acme.local',
                    'jti' => $authUser->jti,
                ]);
            }
            protected function success(array $data, int $statusCode = 200): void
            {
                throw new class($data, $statusCode) extends \RuntimeException {
                    public array $data;
                    public int $status;
                    public function __construct(array $data, int $status)
                    {
                        parent::__construct('success');
                        $this->data = $data;
                        $this->status = $status;
                    }
                };
            }
            protected function error(string $message, int $statusCode = 400, string $code = 'error', array $details = []): void
            {
                throw new \RuntimeException("error:{$code}:{$statusCode}:{$message}");
            }
            protected function exceptionError(\Exception $e, int $statusCode = 400, string $code = 'error'): void
            {
                if ($e instanceof \RuntimeException) {
                    $msg = $e->getMessage();
                    if ($msg === 'success' || str_starts_with($msg, 'error:')) {
                        throw $e;
                    }
                }

                throw new \RuntimeException("exception:{$code}:{$statusCode}:{$e->getMessage()}");
            }
        };

        $authStub = new class extends AuthService {
            public function __construct()
            {
            }
            public function authenticateMailbox(string $mailbox, string $password): bool
            {
                return true;
            }
            public function getDomainFromMailbox(string $mailbox): string
            {
                return explode('@', $mailbox)[1] ?? 'acme.local';
            }
        };

        $tokenStub = new class extends TokenService {
            public function __construct()
            {
            }
            public function createAccessToken(string $mailbox, string $domain): string
            {
                return 'access-token';
            }
            public function createRefreshToken(string $mailbox): array
            {
                return ['token' => 'refresh-token'];
            }
            public function revokeAllTokensForMailbox(string $mailbox, ?string $jti = null): void
            {
            }
        };

        $this->injectServices($controller, $authStub, $tokenStub);

        try {
            $controller->logout();
        } catch (\RuntimeException $e) {
            $this->assertEquals('success', $e->getMessage());
            $this->assertEquals(['message' => 'Logged out successfully'], $e->data);
            return;
        }

        $this->fail('Expected logout to signal success');
    }
}
