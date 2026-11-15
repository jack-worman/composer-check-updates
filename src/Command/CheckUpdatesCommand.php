<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Command;

use Composer\Factory;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterFactory;
use Composer\IO\ConsoleIO;
use JackWorman\ComposerCheckUpdates\ComposerJsonEditor;
use JackWorman\ComposerCheckUpdates\ComposerRunner;
use JackWorman\ComposerCheckUpdates\CooldownPolicy;
use JackWorman\ComposerCheckUpdates\Dependency;
use JackWorman\ComposerCheckUpdates\Hold;
use JackWorman\ComposerCheckUpdates\ListRenderer;
use JackWorman\ComposerCheckUpdates\Report;
use JackWorman\ComposerCheckUpdates\Target;
use JackWorman\ComposerCheckUpdates\Tui\UpgradePicker;
use JackWorman\ComposerCheckUpdates\UpdateFinder;
use JackWorman\ComposerCheckUpdates\Upgrade;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: self::NAME, description: 'Upgrades the constraints in composer.json to the latest versions, like npm-check-updates')]
final class CheckUpdatesCommand extends Command
{
    public const string NAME = 'check-updates';

    /**
     * @param (\Closure(Report): (list<Upgrade>|null))|null $pick       replaces the terminal UI in tests
     * @param (\Closure(): bool)|null                       $isTerminal replaces the TTY check in tests
     */
    public function __construct(
        private readonly ?\Closure $pick = null,
        private readonly ?\Closure $isTerminal = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('filter', InputArgument::IS_ARRAY, 'Only check these packages, * is a wildcard')
            ->addOption('upgrade', 'u', InputOption::VALUE_NONE, 'Write the new constraints to composer.json')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Pick the upgrades and their versions in a terminal UI')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'The version to upgrade to: latest, minor (same major), patch (same minor) or semver (within the current constraint)', 'latest')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only check these packages, * is a wildcard')
            ->addOption('reject', 'x', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip these packages, * is a wildcard')
            ->addOption('dep', null, InputOption::VALUE_REQUIRED, 'The sections to check: prod, dev or prod,dev', 'prod,dev')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'text, group (by patch, minor and major) or json', 'text')
            ->addOption('widen', null, InputOption::VALUE_NONE, 'Keep the versions an "||" constraint allows and append the new one')
            ->addOption('error-level', 'e', InputOption::VALUE_REQUIRED, '1 exits with 0 when there are upgrades, 2 exits with 1', '1')
            ->addOption('install', null, InputOption::VALUE_REQUIRED, 'Run composer update after writing composer.json: always, never or prompt', 'prompt')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'The Composer binary to install with')
            ->addOption('cooldown-period', null, InputOption::VALUE_REQUIRED, 'Overrides policy.cooldown.period, 0 turns the cooldown off')
            ->addOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore all platform requirements (php & ext- packages)')
            ->addOption('ignore-platform-req', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Ignore a platform requirement, * is a wildcard')
            ->addOption('working-dir', 'd', InputOption::VALUE_REQUIRED, 'The directory with the composer.json to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = Target::tryFrom(self::option($input, 'target') ?? '') ?? throw new \InvalidArgumentException('--target must be latest, minor, patch or semver.');
        $format = self::option($input, 'format');
        if (!\in_array($format, ['text', 'group', 'json'], true)) {
            throw new \InvalidArgumentException('--format must be text, group or json.');
        }
        $install = self::option($input, 'install');
        if (!\in_array($install, ['always', 'never', 'prompt'], true)) {
            throw new \InvalidArgumentException('--install must be always, never or prompt.');
        }
        $interactive = (bool) $input->getOption('interactive');
        if ($interactive && !$this->isTerminal($input)) {
            throw new \RuntimeException('--interactive needs a terminal.');
        }

        $workingDir = self::option($input, 'working-dir') ?? getcwd();
        $composerJson = realpath($workingDir.'/composer.json');
        if (false === $composerJson) {
            throw new \RuntimeException(\sprintf('There is no composer.json in %s.', $workingDir));
        }
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $composer = Factory::create(new ConsoleIO($input, $errorOutput, new HelperSet([new QuestionHelper()])), $composerJson, true, true);
        $finder = new UpdateFinder(
            $composer,
            CooldownPolicy::fromConfig($composer->getConfig(), self::option($input, 'cooldown-period')),
            PlatformRequirementFilterFactory::fromBoolOrList(true === $input->getOption('ignore-platform-reqs') ? true : self::options($input->getOption('ignore-platform-req'))),
        );

        if ('json' !== $format) {
            $output->writeln(\sprintf('Checking %s', $composerJson));
        }
        $dependencies = $this->filter(UpdateFinder::readDependencies($composerJson), $input);
        $progress = $errorOutput->isDecorated() && !$output->isQuiet() ? new ProgressBar($errorOutput, \count($dependencies)) : null;
        $report = $finder->find($dependencies, $target, (bool) $input->getOption('widen'), null !== $progress ? $progress->advance(...) : null);
        $progress?->clear();

        $upgrades = $report->upgrades;
        $write = (bool) $input->getOption('upgrade');
        if ($interactive && [] !== $upgrades) {
            $upgrades = $this->pick($report);
            if (null === $upgrades) {
                $output->writeln('Cancelled.');

                return Command::SUCCESS;
            }
            $write = true;
        }

        if ('json' === $format) {
            $output->writeln(json_encode(self::json($upgrades, $report->holds), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));
        } else {
            $renderer = new ListRenderer($output);
            if ([] === $report->upgrades) {
                $output->writeln('All dependencies match the latest package versions :)');
            } elseif ([] === $upgrades) {
                $output->writeln('Nothing selected.');
            } else {
                $output->writeln('');
                $renderer->upgrades($upgrades, 'group' === $format);
            }
            $renderer->holds($report->holds);
        }

        if ([] === $upgrades) {
            return Command::SUCCESS;
        }
        if (!$write) {
            if ('json' !== $format) {
                $output->writeln('');
                $output->writeln('Run <info>ccu -u</info> to upgrade composer.json');
            }

            return '2' === $input->getOption('error-level') ? Command::FAILURE : Command::SUCCESS;
        }

        $original = (string) file_get_contents($composerJson);
        file_put_contents($composerJson, ComposerJsonEditor::apply($original, $upgrades, (bool) $composer->getConfig()->get('sort-packages')));
        $names = array_map(static fn (Upgrade $upgrade): string => $upgrade->name, $upgrades);

        if ('always' === $install || ('prompt' === $install && $this->confirm($input, $output, 'Run composer update now? [Y/n] '))) {
            if (!ComposerRunner::find(self::option($input, 'composer'), \dirname($composerJson))->update($names, $output)) {
                file_put_contents($composerJson, $original);
                $errorOutput->writeln('<error>composer update failed, so composer.json is back the way it was.</error>');

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        if ('json' !== $format) {
            $output->writeln('');
            $output->writeln(\sprintf('Upgraded composer.json. Run <info>composer update %s --with-all-dependencies</info> to install the new versions.', implode(' ', $names)));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<Dependency> $dependencies
     *
     * @return list<Dependency>
     */
    private function filter(array $dependencies, InputInterface $input): array
    {
        $only = self::options($input->getArgument('filter'), $input->getOption('filter'));
        $skip = self::options($input->getOption('reject'));
        $sections = self::options($input->getOption('dep'));

        return array_values(array_filter(
            $dependencies,
            static fn (Dependency $dependency): bool => \in_array($dependency->dev ? 'dev' : 'prod', $sections, true)
                && ([] === $only || self::matches($only, $dependency->name))
                && !self::matches($skip, $dependency->name),
        ));
    }

    /**
     * @param list<string> $patterns
     */
    private static function matches(array $patterns, string $name): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name, \FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private static function option(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }

    /**
     * Splits each value on commas, as npm-check-updates takes "--reject a,b" as well as "--reject a --reject b".
     *
     * @return list<string>
     */
    private static function options(mixed ...$values): array
    {
        $options = [];
        foreach ($values as $value) {
            foreach (\is_array($value) ? $value : [$value] as $item) {
                foreach (\is_string($item) ? explode(',', $item) : [] as $option) {
                    if ('' !== trim($option)) {
                        $options[] = trim($option);
                    }
                }
            }
        }

        return $options;
    }

    /**
     * @param list<Upgrade> $upgrades
     * @param list<Hold>    $holds
     *
     * @return array<string, mixed>
     */
    private static function json(array $upgrades, array $holds): array
    {
        return [
            'upgrades' => array_map(static fn (Upgrade $upgrade): array => [
                'name' => $upgrade->name,
                'dev' => $upgrade->dev,
                'from' => $upgrade->constraint,
                'to' => $upgrade->target()->constraint,
                'installed' => $upgrade->installed,
                'version' => $upgrade->target()->version,
                'level' => $upgrade->target()->level->value,
                'released' => $upgrade->target()->releaseDate?->format(\DATE_ATOM),
            ], $upgrades),
            'held' => array_map(static fn (Hold $hold): array => ['name' => $hold->name, 'version' => $hold->version, 'reason' => $hold->reason], $holds),
        ];
    }

    /**
     * @return list<Upgrade>|null
     */
    private function pick(Report $report): ?array
    {
        return null !== $this->pick ? ($this->pick)($report) : new UpgradePicker()->pick($report);
    }

    private function isTerminal(InputInterface $input): bool
    {
        return null !== $this->isTerminal ? ($this->isTerminal)() : $input->isInteractive() && \defined('STDIN') && stream_isatty(\STDIN);
    }

    private function confirm(InputInterface $input, OutputInterface $output, string $question): bool
    {
        if (!$this->isTerminal($input)) {
            return false;
        }

        $helper = new QuestionHelper();

        return (bool) $helper->ask($input, $output, new ConfirmationQuestion($question, true));
    }
}
