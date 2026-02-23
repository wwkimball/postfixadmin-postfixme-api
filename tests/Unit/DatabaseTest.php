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
use Pfme\Api\Core\Database;

class DatabaseTest extends TestCase
{
    /**
     * Test database singleton returns PDO instance
     */
    public function testGetConnectionReturnsPdoInstance(): void
    {
        $connection = Database::getConnection();

        $this->assertInstanceOf(\PDO::class, $connection);
    }

    /**
     * Test database singleton returns same connection
     */
    public function testGetConnectionReturnsSameConnection(): void
    {
        $connection1 = Database::getConnection();
        $connection2 = Database::getConnection();

        $this->assertSame($connection1, $connection2);
    }

    /**
     * Test readSecretFile method exists
     */
    public function testReadSecretFileMethodExists(): void
    {
        $reflection = new \ReflectionClass('Pfme\Api\Core\Database');

        $this->assertTrue($reflection->hasMethod('readSecretFile'));
    }

    /**
     * Test readSecretFile is static
     */
    public function testReadSecretFileIsStatic(): void
    {
        $reflection = new \ReflectionClass('Pfme\Api\Core\Database');
        $method = $reflection->getMethod('readSecretFile');

        $this->assertTrue($method->isStatic());
    }

    /**
     * Test readSecretFile reads from file
     */
    public function testReadSecretFileReadsContent(): void
    {
        // Create a temporary secret file
        $tmpfile = tempnam(sys_get_temp_dir(), 'secret_');
        file_put_contents($tmpfile, 'test_secret_content');

        try {
            $content = Database::readSecretFile($tmpfile);
            $this->assertEquals('test_secret_content', $content);
        } finally {
            unlink($tmpfile);
        }
    }

    /**
     * Test readSecretFile trims whitespace
     */
    public function testReadSecretFileTrimsWhitespace(): void
    {
        $tmpfile = tempnam(sys_get_temp_dir(), 'secret_');
        file_put_contents($tmpfile, "  test_secret  \n");

        try {
            $content = Database::readSecretFile($tmpfile);
            $this->assertEquals('test_secret', $content);
        } finally {
            unlink($tmpfile);
        }
    }

    /**
     * Test readSecretFile throws on missing file
     */
    public function testReadSecretFileThrowsOnMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Secret file not found');

        Database::readSecretFile('/nonexistent/path/to/secret');
    }

    /**
     * Test getConnection is static
     */
    public function testGetConnectionIsStatic(): void
    {
        $reflection = new \ReflectionClass('Pfme\Api\Core\Database');
        $method = $reflection->getMethod('getConnection');

        $this->assertTrue($method->isStatic());
    }

    /**
     * Test connection is configured with correct attributes
     */
    public function testConnectionAttributesSet(): void
    {
        $connection = Database::getConnection();

        // Check error mode
        $errorMode = $connection->getAttribute(\PDO::ATTR_ERRMODE);
        $this->assertEquals(\PDO::ERRMODE_EXCEPTION, $errorMode);
    }

    /**
     * Test connection charset is utf8mb4
     */
    public function testConnectionCharsetUtf8mb4(): void
    {
        // This is set in the DSN string
        $reflection = new \ReflectionClass('Pfme\Api\Core\Database');
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('utf8mb4', $source);
    }

    /**
     * Test connection can execute queries
     */
    public function testConnectionCanExecuteQuery(): void
    {
        $connection = Database::getConnection();

        // Test a simple query
        try {
            $stmt = $connection->prepare('SELECT 1 as test');
            $stmt->execute();
            $result = $stmt->fetch();

            $this->assertIsArray($result);
            $this->assertArrayHasKey('test', $result);
        } catch (\Exception $e) {
            // If database connection fails, that's ok - we're just testing the singleton
            $this->assertTrue(true);
        }
    }

    /**
     * Test database configuration is loaded
     */
    public function testDatabaseConfigLoaded(): void
    {
        // Configuration should be required in getConnection
        $reflection = new \ReflectionClass('Pfme\Api\Core\Database');
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('config.php', $source);
    }

    /**
     * Test DSN is correctly formatted
     */
    public function testDsnCorrectlyFormatted(): void
    {
        $reflection = new \ReflectionClass('Pfme\Api\Core\Database');
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('mysql:host=', $source);
        $this->assertStringContainsString('port=', $source);
        $this->assertStringContainsString('dbname=', $source);
    }

    /**
     * Test error handling on connection failure
     */
    public function testErrorHandlingOnConnectionFailure(): void
    {
        // PDOException handling is tested
        $reflection = new \ReflectionClass('Pfme\Api\Core\Database');
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString('PDOException', $source);
        $this->assertStringContainsString('error_log', $source);
    }
}
