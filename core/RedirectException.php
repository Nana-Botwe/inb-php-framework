<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Throw from anywhere to end the request with a redirect.
 */
final class RedirectException extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Redirect');
    }
}
