<?php

namespace Pfme\Api\Core;

/**
 * DatabaseHelper - provides database-agnostic SQL functions
 *
 * This class provides abstraction for database-specific SQL functions
 * to support MySQL, PostgreSQL, and SQLite uniformly.
 */
class DatabaseHelper
{
    /**
     * Get current timestamp as database-appropriate string
     */
    public static function now(string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => 'NOW()',
            'sqlite' => "datetime('now')",
            default => 'NOW()', // MySQL/MariaDB
        };
    }

    /**
     * Format a timestamp for insertion based on database type
     *
     * @param int $timestamp Unix timestamp or null for current time
     */
    public static function formatTimestamp(?int $timestamp = null, string $dbType = null): string
    {
        $timestamp = $timestamp ?? time();
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => date('Y-m-d H:i:s', $timestamp),
            'sqlite' => date('Y-m-d H:i:s', $timestamp),
            default => date('Y-m-d H:i:s', $timestamp), // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for subtracting seconds from current time
     *
     * Used for rate limiting and lockout checks
     * Returns a WHERE clause condition like: "attempted_at > <time>"
     */
    public static function subtractSeconds(int $seconds, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => "NOW() - INTERVAL '${seconds} seconds'",
            'sqlite' => "datetime('now', '-${seconds} seconds')",
            default => "DATE_SUB(NOW(), INTERVAL ${seconds} SECOND)", // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for comparing timestamp to a duration in the past
     *
     * Example: WHERE attempted_at > <getSubtractSecondsComparison(300)>
     */
    public static function timestampAfterSeconds(string $column, int $seconds, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();
        $subtractExpr = self::subtractSeconds($seconds, $dbType);

        return match ($dbType) {
            'postgresql' => "${column} > NOW() - INTERVAL '${seconds} seconds'",
            'sqlite' => "${column} > datetime('now', '-${seconds} seconds')",
            default => "${column} > DATE_SUB(NOW(), INTERVAL ${seconds} SECOND)", // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for comparing timestamp to a duration in the past (with parameter binding)
     *
     * This version is used when you need parameter binding for safety.
     * The interval seconds should be passed as a separate parameter.
     */
    public static function timestampAfterSecondsParam(string $column, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => "${column} > NOW() - (? || ' seconds')::INTERVAL",
            'sqlite' => "${column} > datetime('now', '-' || ? || ' seconds')",
            default => "${column} > DATE_SUB(NOW(), INTERVAL ? SECOND)", // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for comparing timestamp to NOW()
     */
    public static function timestampAfterNow(string $column, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => "${column} > NOW()",
            'sqlite' => "${column} > datetime('now')",
            default => "${column} > NOW()", // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for comparing timestamp to NOW() with less-than operator
     */
    public static function timestampBeforeNow(string $column, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => "${column} < NOW()",
            'sqlite' => "${column} < datetime('now')",
            default => "${column} < NOW()", // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for extracting date from timestamp
     */
    public static function extractDate(string $column, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => "DATE(${column})",
            'sqlite' => "date(${column})",
            default => "DATE(${column})", // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for a CASE expression counting based on a condition
     */
    public static function countWhen(string $condition, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => "COUNT(*) FILTER (WHERE ${condition})",
            'sqlite' => "SUM(CASE WHEN ${condition} THEN 1 ELSE 0 END)",
            default => "SUM(IF(${condition}, 1, 0))", // MySQL/MariaDB - IFNULL alternative
        };
    }

    /**
     * Get SQL for aggregating counts with a WHERE condition
     */
    public static function sumCase(string $condition, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => "SUM(CASE WHEN ${condition} THEN 1 ELSE 0 END)",
            'sqlite' => "SUM(CASE WHEN ${condition} THEN 1 ELSE 0 END)",
            default => "SUM(IF(${condition}, 1, 0))", // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for the last inserted ID
     *
     * Note: This should be used after INSERT operations to get the auto-generated ID
     * Different databases return this differently.
     */
    public static function lastInsertId(string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => 'lastval()',
            'sqlite' => 'last_insert_rowid()',
            default => 'LAST_INSERT_ID()', // MySQL/MariaDB
        };
    }

    /**
     * Get SQL for IFNULL (NULL coalescing)
     */
    public static function ifNull(string $column, string $default, string $dbType = null): string
    {
        $dbType = $dbType ?? Database::getType();

        return match ($dbType) {
            'postgresql' => "COALESCE(${column}, ${default})",
            'sqlite' => "IFNULL(${column}, ${default})",
            default => "IFNULL(${column}, ${default})", // MySQL/MariaDB
        };
    }
}
