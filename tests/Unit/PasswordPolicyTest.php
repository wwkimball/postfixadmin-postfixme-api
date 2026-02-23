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
use Pfme\Api\Services\AuthService;

/**
 * Unit tests for password policy validation
 *
 * Tests passphrase requirements including spaces and grammar symbols
 */
class PasswordPolicyTest extends TestCase
{
    private AuthService $authService;

    protected function setUp(): void
    {
        $this->authService = new AuthService();
    }

    /**
     * Test that strong passphrase passes validation
     */
    public function testStrongPassphrasePassesValidation(): void
    {
        $result = $this->authService->validatePasswordPolicy('New Strong Pass 123!', 'Old Pass 456,');

        $this->assertTrue($result['valid'], 'Strong passphrase should pass validation');
        $this->assertEmpty($result['errors'], 'Strong passphrase should have no errors');
    }

    /**
     * Test that password cannot be same as current password
     */
    public function testPasswordCannotBeSameAsCurrent(): void
    {
        $samePassword = 'Same Pass 123!';
        $result = $this->authService->validatePasswordPolicy($samePassword, $samePassword);

        $this->assertFalse($result['valid'], 'Same password should fail validation');
        $this->assertNotEmpty($result['errors'], 'Same password should have errors');
        $this->assertStringContainsString('different', $result['errors'][0]);
    }

    /**
     * Test minimum password length requirement
     */
    public function testPasswordMinimumLength(): void
    {
        $result = $this->authService->validatePasswordPolicy('Short 1!', 'Old Pass 123,');

        // Password should fail if too short (min 10 chars by default)
        $this->assertFalse($result['valid'], 'Short password should fail validation');
        $this->assertStringContainsString('at least', $result['errors'][0]);
    }

    /**
     * Test password requires space
     */
    public function testPasswordRequiresSpace(): void
    {
        $result = $this->authService->validatePasswordPolicy('NewPassword123!', 'OldPass123,');

        // Should fail without space
        $this->assertFalse($result['valid'], 'Password without space should fail');
        $this->assertStringContainsString('space', $result['errors'][0]);
    }

    /**
     * Test password requires grammar symbol
     */
    public function testPasswordRequiresGrammarSymbol(): void
    {
        $result = $this->authService->validatePasswordPolicy('New Password 123', 'Old Pass 456');

        // Should fail without grammar symbol
        $this->assertFalse($result['valid'], 'Password without grammar symbol should fail');
        $this->assertStringContainsString('grammar symbol', $result['errors'][0]);
    }

    /**
     * Test multiple validation failures
     */
    public function testMultipleValidationFailures(): void
    {
        $result = $this->authService->validatePasswordPolicy('short', 'Old Pass 123,');

        // Should fail on multiple requirements
        $this->assertFalse($result['valid'], 'Weak password should fail on multiple requirements');
        $this->assertGreaterThan(1, count($result['errors']), 'Should have multiple error messages');
    }

    /**
     * Test that empty password fails validation
     */
    public function testEmptyPasswordFails(): void
    {
        $result = $this->authService->validatePasswordPolicy('', 'Old Pass 123,');

        $this->assertFalse($result['valid'], 'Empty password should fail validation');
        $this->assertNotEmpty($result['errors'], 'Empty password should have errors');
    }

    /**
     * Test that validation result has correct structure
     */
    public function testValidationResultStructure(): void
    {
        $result = $this->authService->validatePasswordPolicy('New Pass 123!', 'Old Pass 456,');

        $this->assertIsArray($result, 'Result should be an array');
        $this->assertArrayHasKey('valid', $result, 'Result should have valid key');
        $this->assertArrayHasKey('errors', $result, 'Result should have errors key');
        $this->assertIsBool($result['valid'], 'Valid should be boolean');
        $this->assertIsArray($result['errors'], 'Errors should be an array');
    }

    /**
     * Test that errors array contains meaningful messages
     */
    public function testValidationErrorsAreMeaningful(): void
    {
        $result = $this->authService->validatePasswordPolicy('weak', 'Old Pass 123,');

        $this->assertFalse($result['valid'], 'Weak password should fail');
        $this->assertNotEmpty($result['errors'], 'Should have error messages');

        foreach ($result['errors'] as $error) {
            $this->assertIsString($error, 'Each error should be a string');
            $this->assertNotEmpty($error, 'Error message should not be empty');
        }
    }

    /**
     * Test passphrase with multiple spaces
     */
    public function testMultipleSpacesAllowed(): void
    {
        $result = $this->authService->validatePasswordPolicy('This is a long passphrase, okay?', 'Old Pass 123,');

        // Should pass with multiple spaces
        $this->assertTrue($result['valid'], 'Passphrase with multiple spaces should pass');
    }

    /**
     * Test passphrase with multiple grammar symbols
     */
    public function testMultipleGrammarSymbolsAllowed(): void
    {
        $result = $this->authService->validatePasswordPolicy('New Pass 123!@#$', 'Old Pass 456,');

        // Should pass with multiple grammar symbols
        $this->assertTrue($result['valid'], 'Passphrase with multiple symbols should pass');
    }

    /**
     * Test password with unicode characters
     */
    public function testUnicodePasswordHandling(): void
    {
        $unicodePassword = 'Pässwörd 123!';
        $result = $this->authService->validatePasswordPolicy($unicodePassword, 'Old Pass 123,');

        // System should handle unicode gracefully
        $this->assertIsArray($result, 'Should handle unicode passwords');
        $this->assertArrayHasKey('valid', $result, 'Should return validation result');
    }

    /**
     * Test case sensitivity in password comparison
     */
    public function testPasswordCaseSensitivity(): void
    {
        $result = $this->authService->validatePasswordPolicy('New Pass 123!', 'new pass 123!');

        // Different case should be treated as different passwords
        $this->assertTrue($result['valid'], 'Case-different passwords should be considered different');
    }

    /**
     * Test maximum passphrase length handling
     */
    public function testLongPassphraseHandling(): void
    {
        $veryLongPassword = str_repeat('Long Passphrase! ', 20); // ~340 characters
        $result = $this->authService->validatePasswordPolicy($veryLongPassword, 'Old Pass 123,');

        // System should handle long passphrases
        $this->assertIsArray($result, 'Should return validation result array');
        $this->assertArrayHasKey('valid', $result, 'Result should have valid key');
    }
}
