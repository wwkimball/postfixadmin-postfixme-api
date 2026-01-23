<?php

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
