<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

/**
 * A newer release than the one offered, which can't be installed yet.
 */
final readonly class Hold
{
    public function __construct(
        public string $name,
        public string $version,
        public string $reason,
    ) {
    }
}
