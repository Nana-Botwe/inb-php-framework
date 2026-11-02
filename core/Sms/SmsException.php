<?php

declare(strict_types=1);

namespace Core\Sms;

use Core\Exceptions\HasSolution;
use RuntimeException;

final class SmsException extends RuntimeException implements HasSolution
{
    public function __construct(string $message, private readonly string $solution = '')
    {
        parent::__construct($message);
    }

    public function solution(): string
    {
        return $this->solution !== ''
            ? $this->solution
            : 'Check the SMS credentials and sender ID in .env (run "php console sms:install <gateway>" to add them). Sender IDs usually need approval by the provider.';
    }
}
