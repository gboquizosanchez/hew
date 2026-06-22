<?php

declare(strict_types=1);

namespace Boquizo\Hew\Parser;

class ParsedColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        /** @var array<string, mixed> */
        public readonly array $modifiers = [],
    ) {}
}
