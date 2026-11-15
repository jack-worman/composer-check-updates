<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

final readonly class Upgrade
{
    /**
     * @param non-empty-list<Candidate> $candidates from the oldest version to the newest
     */
    public function __construct(
        public string $name,
        public bool $dev,
        public string $constraint,
        public ?string $installed,
        public array $candidates,
        public int $chosen,
    ) {
        if (!isset($candidates[$chosen])) {
            throw new \OutOfRangeException(\sprintf('%s has no candidate %d.', $name, $chosen));
        }
    }

    public function target(): Candidate
    {
        return $this->candidates[$this->chosen];
    }

    public function choose(int $index): self
    {
        return new self($this->name, $this->dev, $this->constraint, $this->installed, $this->candidates, $index);
    }
}
