<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tests\Command;

use Composer\Pcre\Preg;
use JackWorman\ComposerCheckUpdates\Command\CheckUpdatesCommand;
use JackWorman\ComposerCheckUpdates\Report;
use JackWorman\ComposerCheckUpdates\Tests\Project;
use JackWorman\ComposerCheckUpdates\Upgrade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckUpdatesCommandTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        $this->project = new Project()
            ->release('acme/framework', '1.0.0', '300 days')
            ->release('acme/framework', '1.1.0', '200 days')
            ->release('acme/framework', '1.1.1', '100 days')
            ->release('acme/framework', '2.0.0', '50 days')
            ->release('acme/framework', '2.1.0', '20 days')
            ->release('acme/framework', '3.0.0', '2 days')
            ->release('acme/logger', '1.4.0', '300 days')
            ->release('acme/logger', '1.4.2', '30 days')
            ->release('acme/http', '5.0.0', '300 days')
            ->release('acme/http', '6.0.0', '30 days', ['php' => '>=99'])
            ->release('acme/tests', '9.0.0', '300 days')
            ->release('acme/tests', '10.1.0', '30 days');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
    }

    public function testListsUpgradesBeyondTheConstraints(): void
    {
        $this->writeProject();

        $tester = $this->ccu([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString(<<<'TEXT'
             acme/framework    ^1.0.0  →  ^2.1.0
             acme/tests (dev)  ^9.0.0  →  ^10.1.0
            TEXT, $tester->getDisplay());
        self::assertStringNotContainsString('acme/logger', $tester->getDisplay());
        self::assertStringContainsString('Run ccu -u to upgrade composer.json', $tester->getDisplay());
        self::assertSame(['acme/framework' => '^1.0.0', 'acme/logger' => '^1.4', 'acme/http' => '^5.0.0'], $this->project->requirements());
    }

    public function testReportsReleasesHeldBackByTheCooldownAndThePlatform(): void
    {
        $this->writeProject();

        $display = $this->ccu([])->getDisplay();

        self::assertMatchesRegularExpression('{acme/framework 3\.0\.0 was released \d{4}-\d\d-\d\d and is in the cooldown for another 5 days}', $display);
        self::assertStringContainsString('acme/http 6.0.0 requires php >=99', $display);
        self::assertStringNotContainsString('acme/http   ', $display);
    }

    public function testCooldownPeriodOverridesTheConfiguredOne(): void
    {
        $this->writeProject();

        $display = $this->ccu(['--cooldown-period' => '0'])->getDisplay();

        self::assertStringContainsString('acme/framework    ^1.0.0  →  ^3.0.0', $display);
        self::assertStringNotContainsString('cooldown', $display);
    }

    public function testFallsBackToTheConstraintsLowerBoundWithoutALockFile(): void
    {
        $this->project->write(['acme/framework' => '^1.1.0'], config: ['policy' => ['cooldown' => ['period' => '7 days']]]);

        self::assertStringContainsString('acme/framework  ^1.1.0  →  ^2.1.0', $this->ccu([])->getDisplay());
    }

    public function testUpgradeWritesComposerJson(): void
    {
        $this->writeProject();

        $tester = $this->ccu(['-u' => true, '--install' => 'never']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(['acme/framework' => '^2.1.0', 'acme/logger' => '^1.4', 'acme/http' => '^5.0.0'], $this->project->requirements());
        self::assertSame(['acme/tests' => '^10.1.0'], $this->project->requirements('require-dev'));
        self::assertStringContainsString('Run composer update acme/framework acme/tests --with-all-dependencies to install the new versions.', $tester->getDisplay());
    }

    public function testUpgradeKeepsTheFilesFormatting(): void
    {
        $this->writeProject();
        $before = $this->project->composerJson();

        $this->ccu(['-u' => true, '--install' => 'never', '--filter' => ['acme/framework']]);

        self::assertSame(str_replace('"acme/framework": "^1.0.0"', '"acme/framework": "^2.1.0"', $before), $this->project->composerJson());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTargets(): iterable
    {
        yield 'minor' => ['minor', '^1.1.1'];
        yield 'patch' => ['patch', '^1.0.0'];
        yield 'semver' => ['semver', '^1.1.1'];
    }

    #[DataProvider('provideTargets')]
    public function testTarget(string $target, string $expected): void
    {
        $this->writeProject();

        $this->ccu(['-u' => true, '--install' => 'never', '--target' => $target, 'filter' => ['acme/framework']]);

        self::assertSame($expected, $this->project->requirements()['acme/framework']);
    }

    public function testFiltersPackagesAndSections(): void
    {
        $this->writeProject();

        self::assertStringNotContainsString('acme/framework', $this->ccu(['filter' => ['acme/t*']])->getDisplay());
        self::assertStringNotContainsString('acme/framework', $this->ccu(['--reject' => ['acme/framework,acme/logger']])->getDisplay());
        self::assertStringNotContainsString('acme/tests', $this->ccu(['--dep' => 'prod'])->getDisplay());
        self::assertStringNotContainsString('acme/framework', $this->ccu(['--dep' => 'dev'])->getDisplay());
    }

    public function testGroupsByLevel(): void
    {
        $this->writeProject();

        $display = $this->ccu(['--format' => 'group'])->getDisplay();

        self::assertMatchesRegularExpression('{Major  Potentially breaking changes\n acme/framework +\^1\.0\.0  →  \^2\.1\.0\n acme/tests \(dev\) +\^9\.0\.0  →  \^10\.1\.0\n}', $display);
    }

    public function testJson(): void
    {
        $this->writeProject();

        $json = $this->ccu(['--format' => 'json'])->getDisplay();

        self::assertMatchesRegularExpression('{"released": "\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d"}', $json);
        self::assertJsonStringEqualsJsonString((string) json_encode([
            'upgrades' => [
                ['name' => 'acme/framework', 'dev' => false, 'from' => '^1.0.0', 'to' => '^2.1.0', 'installed' => '1.0.0', 'version' => '2.1.0', 'level' => 'major', 'released' => 'date'],
                ['name' => 'acme/tests', 'dev' => true, 'from' => '^9.0.0', 'to' => '^10.1.0', 'installed' => '9.0.0', 'version' => '10.1.0', 'level' => 'major', 'released' => 'date'],
            ],
            'held' => [
                ['name' => 'acme/framework', 'version' => '3.0.0', 'reason' => 'cooldown'],
                ['name' => 'acme/http', 'version' => '6.0.0', 'reason' => 'requires php >=99'],
            ],
        ]), Preg::replace(['{"released": "[^"]+"}', '{"reason": "was released [^"]+ and is in the cooldown for another [^"]+"}'], ['"released": "date"', '"reason": "cooldown"'], $json));
    }

    public function testErrorLevelTwoFailsWhenThereAreUpgrades(): void
    {
        $this->writeProject();

        self::assertSame(Command::FAILURE, $this->ccu(['--error-level' => '2'])->getStatusCode());
        self::assertSame(Command::SUCCESS, $this->ccu(['--error-level' => '2', 'filter' => ['acme/http']])->getStatusCode());
    }

    public function testInteractiveWritesOnlyWhatWasPicked(): void
    {
        $this->writeProject();
        $offered = null;

        $tester = $this->ccu(['-i' => true, '--install' => 'never'], static function (Report $report) use (&$offered): array {
            $offered = $report;

            return [$report->upgrades[0]->choose(0)];
        });

        self::assertInstanceOf(Report::class, $offered);
        self::assertSame(['1.1.1', '2.1.0'], array_map(static fn ($candidate): string => $candidate->version, $offered->upgrades[0]->candidates));
        self::assertSame('^1.1.1', $this->project->requirements()['acme/framework']);
        self::assertSame('^9.0.0', $this->project->requirements('require-dev')['acme/tests']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    public function testInteractiveCancelChangesNothing(): void
    {
        $this->writeProject();
        $before = $this->project->composerJson();

        $tester = $this->ccu(['-i' => true], static fn (): ?array => null);

        self::assertStringContainsString('Cancelled.', $tester->getDisplay());
        self::assertSame($before, $this->project->composerJson());
    }

    public function testInstallRunsComposerUpdate(): void
    {
        $this->writeProject();
        $composer = $this->fakeComposer(0);

        $tester = $this->ccu(['-u' => true, '--install' => 'always', '--composer' => $composer]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame('update acme/framework acme/tests --with-all-dependencies', file_get_contents($this->project->dir.'/arguments'));
        self::assertSame('^2.1.0', $this->project->requirements()['acme/framework']);
    }

    public function testFailedInstallRestoresComposerJson(): void
    {
        $this->writeProject();
        $before = $this->project->composerJson();

        $tester = $this->ccu(['-u' => true, '--install' => 'always', '--composer' => $this->fakeComposer(2)]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($before, $this->project->composerJson());
    }

    private function writeProject(): void
    {
        $this->project->write(
            ['acme/framework' => '^1.0.0', 'acme/logger' => '^1.4', 'acme/http' => '^5.0.0'],
            ['acme/tests' => '^9.0.0'],
            ['policy' => ['cooldown' => ['period' => '7 days']]],
            ['acme/framework' => '1.0.0', 'acme/logger' => '1.4.2', 'acme/http' => '5.0.0', 'acme/tests' => '9.0.0'],
        );
    }

    private function fakeComposer(int $exitCode): string
    {
        $path = $this->project->dir.'/fake-composer.phar';
        file_put_contents($path, \sprintf('<?php file_put_contents(__DIR__."/arguments", implode(" ", array_slice($argv, 1))); exit(%d);', $exitCode));

        return $path;
    }

    /**
     * @param array<string, mixed>                          $input
     * @param (\Closure(Report): (list<Upgrade>|null))|null $pick
     */
    private function ccu(array $input, ?\Closure $pick = null): CommandTester
    {
        $tester = new CommandTester(new CheckUpdatesCommand($pick, static fn (): bool => null !== $pick));
        $tester->execute(['--working-dir' => $this->project->dir, ...$input], ['decorated' => false, 'capture_stderr_separately' => true]);

        return $tester;
    }
}
