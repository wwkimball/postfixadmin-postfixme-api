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

    // SEC-026: Authorization bypass prevention tests

    public function testIsUserAuthorizedForAliasWithExactMatch(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Exact match should be authorized
        $goto = 'user@example.com,other@example.com';
        $mailbox = 'user@example.com';

        $this->assertTrue($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasRejectsSubstringMatch(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Substring match should NOT be authorized (SEC-026 vulnerability fix)
        $goto = 'anotheruser@example.com,targetuser@example.com';
        $mailbox = 'user@example.com';

        $this->assertFalse($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasHandlesCaseInsensitivity(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Case-insensitive match (email addresses are case-insensitive per RFC)
        $goto = 'User@Example.COM,other@example.com';
        $mailbox = 'user@example.com';

        $this->assertTrue($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasHandlesWhitespace(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Whitespace around destinations should be trimmed
        $goto = 'user@example.com , other@example.com , third@example.com';
        $mailbox = 'other@example.com';

        $this->assertTrue($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasSingleDestination(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Single destination (no comma)
        $goto = 'user@example.com';
        $mailbox = 'user@example.com';

        $this->assertTrue($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasNotInDestinations(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Mailbox not in destinations list
        $goto = 'other@example.com,another@example.com';
        $mailbox = 'user@example.com';

        $this->assertFalse($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasRejectsEmptyMailbox(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Empty mailbox should not match
        $goto = 'user@example.com,other@example.com';
        $mailbox = '';

        $this->assertFalse($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasRejectsEmptyGoto(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Empty goto field should not authorize anyone
        $goto = '';
        $mailbox = 'user@example.com';

        $this->assertFalse($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasRejectsPartialEmailMatch(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Partial matches should fail - different edge cases

        // Test 1: "admin@domain.com" should not match "webadmin@domain.com"
        $goto = 'webadmin@domain.com,support@domain.com';
        $mailbox = 'admin@domain.com';
        $this->assertFalse($method->invoke($this->aliasService, $goto, $mailbox));

        // Test 2: "user@example.com" should not match when embedded in longer address
        $goto = 'superuser@example.com,user@example.com.au';
        $mailbox = 'user@example.com';
        $this->assertFalse($method->invoke($this->aliasService, $goto, $mailbox));

        // Test 3: Local part substring should fail
        $goto = 'testuser@domain.com,myuser@domain.com';
        $mailbox = 'user@domain.com';
        $this->assertFalse($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasWithLeadingTrailingWhitespace(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Mailbox parameter with leading/trailing whitespace should still match
        $goto = 'user@example.com,other@example.com';
        $mailbox = '  user@example.com  ';

        $this->assertTrue($method->invoke($this->aliasService, $goto, $mailbox));
    }

    public function testIsUserAuthorizedForAliasMultipleOccurrences(): void
    {
        $reflection = new \ReflectionClass($this->aliasService);
        $method = $reflection->getMethod('isUserAuthorizedForAlias');
        $method->setAccessible(true);

        // Duplicate destination (edge case: same address appears twice)
        $goto = 'user@example.com,other@example.com,user@example.com';
        $mailbox = 'user@example.com';

        $this->assertTrue($method->invoke($this->aliasService, $goto, $mailbox));
    }
}
