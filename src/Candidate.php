<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

final readonly class Candidate
{
    public function __construct(
        public string $version,
        public string $constraint,
        public Level $level,
        public ?\DateTimeInterface $releaseDate,
    ) {
    }
}
