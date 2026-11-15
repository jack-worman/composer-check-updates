<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

final readonly class Report
{
    /**
     * @param list<Upgrade> $upgrades
     * @param list<Hold>    $holds
     */
    public function __construct(
        public array $upgrades,
        public array $holds,
    ) {
    }
}
