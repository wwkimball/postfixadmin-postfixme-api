<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Controllers\AliasController;
use Pfme\Api\Core\Database;

class AliasControllerBehaviorTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = Database::getConnection();
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function testListReturnsPaginatedData(): void
    {
        $_GET = ['page' => 1, 'per_page' => 5, 'sort' => 'address', 'order' => 'asc'];
        $authUser = ['mailbox' => 'user1@acme.local', 'domain' => 'acme.local'];

        $controller = new class($authUser) extends AliasController {
            public function __construct(array $authUser)
            {
                parent::__construct($authUser);
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
            protected function getQueryParams(): array
            {
                return $_GET;
            }
            protected function error(string $message, int $statusCode = 400, string $code = 'error', array $details = []): void
            {
                throw new \RuntimeException("error:{$code}:{$statusCode}:{$message}");
            }
            protected function exceptionError(\Exception $e, int $statusCode = 400, string $code = 'error'): void
            {
                throw new \RuntimeException("exception:{$code}:{$statusCode}:{$e->getMessage()}");
            }
        };

        try {
            $controller->list();
        } catch (\RuntimeException $e) {
            $this->assertEquals('success', $e->getMessage());
            $this->assertArrayHasKey('data', $e->data);
            $this->assertArrayHasKey('meta', $e->data);
            $this->assertEquals(200, $e->status);
            $this->assertLessThanOrEqual(5, count($e->data['data']));
            $this->assertEquals(1, $e->data['meta']['page']);
            return;
        }

        $this->fail('Expected list to signal success');
    }

    public function testCreateValidAliasSucceeds(): void
    {
        $local = 'qa-controller-' . uniqid();
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $controller = new class($local) extends AliasController {
            private string $local;
            public function __construct(string $local)
            {
                $this->local = $local;
                parent::__construct([
                    'mailbox' => 'user1@acme.local',
                    'domain' => 'acme.local',
                ]);
            }
            protected function getJsonInput(): array
            {
                return [
                    'local_part' => $this->local,
                    'destinations' => ['user1@acme.local'],
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
                if ($e instanceof \RuntimeException && $e->getMessage() === 'success') {
                    throw $e; // propagate sentinel success without converting to error
                }

                throw new \RuntimeException("exception:{$code}:{$statusCode}:{$e->getMessage()}");
            }
        };

        try {
            $controller->create();
        } catch (\RuntimeException $e) {
            $this->assertEquals('success', $e->getMessage());
            $this->assertEquals(201, $e->status);
            $this->assertArrayHasKey('address', $e->data);
            $this->assertStringStartsWith($local . '@acme.local', $e->data['address']);
            return;
        }

        $this->fail('Expected create to signal success');
    }

    public function testCreateRequiresDestinations(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = new class extends AliasController {
            public function __construct()
            {
                parent::__construct([
                    'mailbox' => 'user1@acme.local',
                    'domain' => 'acme.local',
                ]);
            }
            protected function getJsonInput(): array
            {
                return [
                    'local_part' => 'missing-dests',
                    'destinations' => [],
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
                throw new \RuntimeException("exception:{$code}:{$statusCode}:{$e->getMessage()}");
            }
        };

        try {
            $controller->create();
            $this->fail('Expected validation error');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('error:invalid_input:400', $e->getMessage());
        }
    }
}
