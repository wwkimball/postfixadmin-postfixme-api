<?php

namespace Pfme\Api\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Pfme\Api\Services\ValidationService;

class ValidationServiceTest extends TestCase
{
    private ValidationService $validationService;

    protected function setUp(): void
    {
        $this->validationService = new ValidationService();
    }

    /**
     * Test valid local parts pass validation
     */
    public function testValidateLocalPartAcceptsValidFormats(): void
    {
        $validParts = [
            'user',
            'user.name',
            'user+tag',
            'user_name',
            'user-name',
            'first.last',
            '123',
            'a',
            'user!',
            'user#name',
            'user$name',
            'user%name',
            'user&name',
            "user'name",
            'user*name',
            'user/name',
            'user=name',
            'user?name',
            'user^name',
            'user`name',
            'user{name}',
            'user|name',
            'user~name',
        ];

        foreach ($validParts as $part) {
            $result = $this->validationService->validateLocalPart($part);
            $this->assertTrue($result['valid'], "Failed for: $part - " . ($result['error'] ?? ''));
            $this->assertNull($result['error']);
        }
    }

    /**
     * Test empty local part fails validation
     */
    public function testValidateLocalPartRejectsEmpty(): void
    {
        $result = $this->validationService->validateLocalPart('');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Local part cannot be empty', $result['error']);
    }

    /**
     * Test local part exceeding 64 characters fails
     */
    public function testValidateLocalPartRejectsExceedsMaxLength(): void
    {
        $longPart = str_repeat('a', 65);
        $result = $this->validationService->validateLocalPart($longPart);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Local part cannot exceed 64 characters', $result['error']);
    }

    /**
     * Test local part at exactly 64 characters passes
     */
    public function testValidateLocalPartAccepts64Characters(): void
    {
        $part = str_repeat('a', 64);
        $result = $this->validationService->validateLocalPart($part);

        $this->assertTrue($result['valid']);
    }

    /**
     * Test leading dot fails validation
     */
    public function testValidateLocalPartRejectsLeadingDot(): void
    {
        $result = $this->validationService->validateLocalPart('.user');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Local part cannot start or end with a dot', $result['error']);
    }

    /**
     * Test trailing dot fails validation
     */
    public function testValidateLocalPartRejectsTrailingDot(): void
    {
        $result = $this->validationService->validateLocalPart('user.');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Local part cannot start or end with a dot', $result['error']);
    }

    /**
     * Test consecutive dots fail validation
     */
    public function testValidateLocalPartRejectsConsecutiveDots(): void
    {
        $result = $this->validationService->validateLocalPart('user..name');

        $this->assertFalse($result['valid']);
        $this->assertEquals('Local part cannot contain consecutive dots', $result['error']);
    }

    /**
     * Test invalid characters fail validation
     */
    public function testValidateLocalPartRejectsInvalidCharacters(): void
    {
        $invalidParts = [
            'user@domain',  // @ not allowed
            'user name',    // space not allowed
            'user<name>',   // angle brackets not allowed
            'user[name]',   // brackets not allowed
            'user\\name',   // backslash not allowed
            'user"name',    // quotes not allowed
            'user,name',    // comma not allowed
            'user;name',    // semicolon not allowed
            'user:name',    // colon not allowed
        ];

        foreach ($invalidParts as $part) {
            $result = $this->validationService->validateLocalPart($part);
            $this->assertFalse($result['valid'], "Should reject: $part");
            $this->assertEquals('Local part contains invalid characters', $result['error']);
        }
    }

    /**
     * Test case sensitivity handling
     */
    public function testValidateLocalPartHandlesLowerAndUppercase(): void
    {
        $result1 = $this->validationService->validateLocalPart('User');
        $result2 = $this->validationService->validateLocalPart('USER');
        $result3 = $this->validationService->validateLocalPart('user');

        $this->assertTrue($result1['valid']);
        $this->assertTrue($result2['valid']);
        $this->assertTrue($result3['valid']);
    }

    /**
     * Test numeric local parts pass
     */
    public function testValidateLocalPartAcceptsNumericParts(): void
    {
        $result = $this->validationService->validateLocalPart('12345');

        $this->assertTrue($result['valid']);
    }

    /**
     * Test single character local part passes
     */
    public function testValidateLocalPartAcceptsSingleCharacter(): void
    {
        $result = $this->validationService->validateLocalPart('a');

        $this->assertTrue($result['valid']);
    }

    /**
     * Test plus sign (common for Gmail-style addressing)
     */
    public function testValidateLocalPartAcceptsPlusSign(): void
    {
        $result = $this->validationService->validateLocalPart('user+tag');

        $this->assertTrue($result['valid']);
    }

    /**
     * Test hyphen in middle is valid
     */
    public function testValidateLocalPartAcceptsHyphen(): void
    {
        $result = $this->validationService->validateLocalPart('first-last');

        $this->assertTrue($result['valid']);
    }

    /**
     * Test underscore is valid
     */
    public function testValidateLocalPartAcceptsUnderscore(): void
    {
        $result = $this->validationService->validateLocalPart('first_last');

        $this->assertTrue($result['valid']);
    }

    /**
     * Test return value structure
     */
    public function testValidateLocalPartReturnsCorrectStructure(): void
    {
        $result = $this->validationService->validateLocalPart('user');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('valid', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertIsBool($result['valid']);
        $this->assertNull($result['error']);
    }

    /**
     * Test valid result has null error
     */
    public function testValidLocalPartHasNullError(): void
    {
        $result = $this->validationService->validateLocalPart('valid.user');

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    /**
     * Test invalid result has string error
     */
    public function testInvalidLocalPartHasStringError(): void
    {
        $result = $this->validationService->validateLocalPart('');

        $this->assertFalse($result['valid']);
        $this->assertIsString($result['error']);
        $this->assertNotEmpty($result['error']);
    }
}
