<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\AliasService;

class AliasServiceTest extends TestCase
{
    private AliasService $aliasService;

    protected function setUp(): void
    {
        $this->aliasService = new AliasService();
    }

    public function testGetSortColumnAllowsWhitelistedValues(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('getSortColumn');
        $method->setAccessible(true);

        $this->assertEquals('a.address ASC', $method->invoke($this->aliasService, 'address'));
        $this->assertEquals('a.created DESC', $method->invoke($this->aliasService, 'created'));
        $this->assertEquals('a.modified DESC', $method->invoke($this->aliasService, 'modified'));
    }

    public function testGetSortColumnFallsBackToDefault(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('getSortColumn');
        $method->setAccessible(true);

        $this->assertEquals('a.address ASC', $method->invoke($this->aliasService, 'invalid-sort'));
    }
}
