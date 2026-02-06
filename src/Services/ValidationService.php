<?php

namespace Pfme\Api\Services;

/**
 * Validation Service - provides validation methods for common data formats
 */
class ValidationService
{
    /**
     * Validates email local part against RFC 5321 standards
     *
     * @param string $localPart The email local part to validate
     * @return array Array with 'valid' (bool) and 'error' (string|null) keys
     *
     * RFC 5321 rules:
     * - Length: 1-64 characters
     * - Allowed characters: a-z, A-Z, 0-9, and special chars !#$%&'*+-/=?^_`{|}~
     * - Cannot start or end with a dot
     * - Cannot contain consecutive dots
     */
    public function validateLocalPart(string $localPart): array
    {
        // Check length
        if (strlen($localPart) === 0) {
            return [
                'valid' => false,
                'error' => 'Local part cannot be empty',
            ];
        }

        if (strlen($localPart) > 64) {
            return [
                'valid' => false,
                'error' => 'Local part cannot exceed 64 characters',
            ];
        }

        // Check for leading or trailing dots
        if ($localPart[0] === '.' || $localPart[strlen($localPart) - 1] === '.') {
            return [
                'valid' => false,
                'error' => 'Local part cannot start or end with a dot',
            ];
        }

        // Check for consecutive dots
        if (strpos($localPart, '..') !== false) {
            return [
                'valid' => false,
                'error' => 'Local part cannot contain consecutive dots',
            ];
        }

        // Check allowed characters: a-z, A-Z, 0-9, and special chars !#$%&'*+-/=?^_`{|}~
        // Using regex: /^[a-zA-Z0-9.!#$%&'*+\-/=?^_`{|}~]+$/
        if (!preg_match('/^[a-zA-Z0-9.!#$%&\'*+\-\/=?^_`{|}~]+$/', $localPart)) {
            return [
                'valid' => false,
                'error' => 'Local part contains invalid characters',
            ];
        }

        return [
            'valid' => true,
            'error' => null,
        ];
    }
}
