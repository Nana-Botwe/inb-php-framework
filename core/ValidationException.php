<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Thrown by Controller::validate(). The App turns it into "redirect back with errors"
 * for normal requests, or a 422 JSON response for API/AJAX requests.
 */
final class ValidationException extends RuntimeException
{
    /** @param array<string, string[]> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The given data was invalid.', 422);
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }
}
