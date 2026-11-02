<?php

declare(strict_types=1);

namespace Core\Exceptions;

/**
 * Exceptions that know how to fix themselves. The debug error page shows solution() in a highlighted box.
 */
interface HasSolution
{
    public function solution(): string;
}
