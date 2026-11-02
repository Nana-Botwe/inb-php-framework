<?php

declare(strict_types=1);

namespace Core\Payments;

use Core\Exceptions\HasSolution;
use RuntimeException;

final class PaymentException extends RuntimeException implements HasSolution
{
    public function __construct(string $message, private readonly string $solution = '')
    {
        parent::__construct($message);
    }

    public function solution(): string
    {
        return $this->solution !== ''
            ? $this->solution
            : 'Check the gateway credentials in .env (run "php console payment:install <gateway>" to add them) and whether you are using test or live keys.';
    }
}
