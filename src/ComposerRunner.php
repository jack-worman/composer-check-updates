<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final readonly class ComposerRunner
{
    /**
     * @param list<string> $command
     */
    private function __construct(
        private array $command,
        private string $workingDir,
    ) {
    }

    /**
     * Prefers $binary, then the Composer running a script, then "composer" from the PATH.
     */
    public static function find(?string $binary, string $workingDir): self
    {
        if (null === $binary) {
            $fromScript = getenv('COMPOSER_BINARY');
            $binary = false !== $fromScript && '' !== $fromScript ? $fromScript : new ExecutableFinder()->find('composer');
        }
        if (null === $binary) {
            throw new \RuntimeException('Composer was not found in the PATH, pass its path with --composer.');
        }

        return new self(str_ends_with($binary, '.phar') ? [\PHP_BINARY, $binary] : [$binary], $workingDir);
    }

    /**
     * @param list<string> $packages
     *
     * @return list<string>
     */
    private function updateCommand(array $packages): array
    {
        return [...$this->command, 'update', ...$packages, '--with-all-dependencies'];
    }

    /**
     * @param list<string> $packages
     */
    public function update(array $packages, OutputInterface $output): bool
    {
        $process = new Process($this->updateCommand($packages), $this->workingDir, null, null, null);
        if (\defined('STDOUT') && stream_isatty(\STDOUT) && Process::isTtySupported()) {
            $process->setTty(true);
        }

        return 0 === $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });
    }
}
