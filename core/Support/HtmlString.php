<?php

declare(strict_types=1);

namespace Core\Support;

use Stringable;

/**
 * Trusted HTML that {{ }} / e() will NOT escape (e.g. pagination links built by the framework).
 * Never wrap user input in this.
 */
final class HtmlString implements Stringable
{
    public function __construct(private readonly string $html)
    {
    }

    public function toHtml(): string
    {
        return $this->html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
