<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;

final readonly class ListRenderer
{
    public function __construct(
        private OutputInterface $output,
    ) {
    }

    /**
     * @param list<Upgrade> $upgrades
     */
    public function upgrades(array $upgrades, bool $group): void
    {
        if ([] === $upgrades) {
            return;
        }
        if (!$group) {
            $this->rows($upgrades);

            return;
        }

        foreach (Level::cases() as $level) {
            $rows = array_values(array_filter($upgrades, static fn (Upgrade $upgrade): bool => $upgrade->target()->level === $level));
            if ([] === $rows) {
                continue;
            }
            $this->output->writeln('');
            $this->output->writeln(\sprintf('<fg=%s;options=bold>%s</>  %s', $level->color(), $level->title(), $level->description()));
            $this->rows($rows);
        }
    }

    /**
     * @param list<Hold> $holds
     */
    public function holds(array $holds): void
    {
        if ([] === $holds) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('<options=bold>Held back</>');
        foreach ($holds as $hold) {
            $this->output->writeln(OutputFormatter::escape(\sprintf(' %s %s %s', $hold->name, $hold->version, $hold->reason)));
        }
    }

    /**
     * @param non-empty-list<Upgrade> $upgrades
     */
    private function rows(array $upgrades): void
    {
        $names = array_map(self::label(...), $upgrades);
        $nameWidth = max(array_map(Helper::width(...), $names));
        $fromWidth = max(array_map(static fn (Upgrade $upgrade): int => Helper::width($upgrade->constraint), $upgrades));

        foreach ($upgrades as $index => $upgrade) {
            $target = $upgrade->target();
            [$same, $changed] = Highlight::split($upgrade->constraint, $target->constraint);
            $this->output->writeln(\sprintf(
                ' %s  %s  →  %s<fg=%s>%s</>',
                OutputFormatter::escape(str_pad($names[$index], $nameWidth + \strlen($names[$index]) - Helper::width($names[$index]))),
                OutputFormatter::escape(str_pad($upgrade->constraint, $fromWidth)),
                OutputFormatter::escape($same),
                $target->level->color(),
                OutputFormatter::escape($changed),
            ));
        }
    }

    private static function label(Upgrade $upgrade): string
    {
        return $upgrade->name.($upgrade->dev ? ' (dev)' : '');
    }
}
