<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

final readonly class Dependency
{
    public function __construct(
        public string $name,
        public string $constraint,
        public bool $dev,
    ) {
    }
}
