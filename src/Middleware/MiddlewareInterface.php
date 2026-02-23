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


namespace Pfme\Api\Middleware;

/**
 * Interface for all middleware components
 */
interface MiddlewareInterface
{
    /**
     * Handle the middleware logic
     * Should terminate execution (via exit or exception) if request should not proceed
     */
    public function handle(): void;
}
