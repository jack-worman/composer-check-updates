<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

use Composer\Composer;
use Composer\Filter\PlatformRequirementFilter\PlatformRequirementFilterInterface;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionSelector;
use Composer\Pcre\Preg;
use Composer\Policy\CooldownPolicyConfig;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;
use Composer\Semver\Comparator;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;

/**
 * Finds the versions Composer would install for each root requirement if its constraint allowed them.
 */
final class UpdateFinder
{
    private readonly VersionSelector $selector;
    private readonly RepositoryInterface $installed;
    private readonly PlatformRepository $platform;
    private readonly VersionParser $parser;

    public function __construct(
        private readonly Composer $composer,
        private readonly ?CooldownPolicyConfig $cooldown,
        private readonly PlatformRequirementFilterInterface $platformRequirementFilter,
    ) {
        $root = $composer->getPackage();
        $repositorySet = new RepositorySet($root->getMinimumStability(), $root->getStabilityFlags());
        $repositorySet->addRepository(new CompositeRepository($composer->getRepositoryManager()->getRepositories()));

        $overrides = [];
        $platform = $composer->getConfig()->get('platform');
        foreach (\is_array($platform) ? $platform : [] as $name => $version) {
            if (\is_string($name) && (\is_string($version) || false === $version)) {
                $overrides[$name] = $version;
            }
        }
        $this->platform = new PlatformRepository([], $overrides);
        $this->selector = new VersionSelector($repositorySet, $this->platform, $cooldown);
        $locker = $composer->getLocker();
        $this->installed = $locker->isLocked() ? $locker->getLockedRepository(true) : $composer->getRepositoryManager()->getLocalRepository();
        $this->parser = new VersionParser();
    }

    /**
     * @return list<Dependency>
     */
    public static function readDependencies(string $composerJson): array
    {
        $data = json_decode((string) file_get_contents($composerJson), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \UnexpectedValueException(\sprintf('%s does not contain an object.', $composerJson));
        }
        $dependencies = [];
        foreach (['require' => false, 'require-dev' => true] as $key => $dev) {
            foreach (\is_array($data[$key] ?? null) ? $data[$key] : [] as $name => $constraint) {
                if (\is_string($name) && \is_string($constraint)) {
                    $dependencies[] = new Dependency($name, $constraint, $dev);
                }
            }
        }

        return $dependencies;
    }

    /**
     * @param list<Dependency>        $dependencies
     * @param (callable(): void)|null $advance
     */
    public function find(array $dependencies, Target $target, bool $widen = false, ?callable $advance = null): Report
    {
        $upgrades = [];
        $holds = [];
        foreach ($dependencies as $dependency) {
            if ($this->isSupported($dependency)) {
                [$upgrade, $hold] = $this->check($dependency, $target, $widen);
                if (null !== $upgrade) {
                    $upgrades[] = $upgrade;
                }
                if (null !== $hold) {
                    $holds[] = $hold;
                }
            }
            if (null !== $advance) {
                $advance();
            }
        }

        return new Report($upgrades, $holds);
    }

    private function isSupported(Dependency $dependency): bool
    {
        return !PlatformRepository::isPlatformPackage($dependency->name)
            && '*' !== trim($dependency->constraint)
            && !str_starts_with($dependency->constraint, 'dev-')
            && !str_contains($dependency->constraint, ' as ')
            && 'self.version' !== $dependency->constraint;
    }

    /**
     * @return array{?Upgrade, ?Hold}
     */
    private function check(Dependency $dependency, Target $target, bool $widen): array
    {
        $installed = $this->installed->findPackage($dependency->name, '*');
        while ($installed instanceof AliasPackage) {
            $installed = $installed->getAliasOf();
        }
        if (null !== $installed && $installed->isDev()) {
            return [null, null];
        }

        $base = $installed?->getVersion() ?? $this->lowerBound($dependency->constraint);
        $stability = $this->preferredStability($dependency->name, $installed);

        $latest = $this->best($dependency->name, null, $stability);
        $hold = $this->hold($dependency->name, $latest);
        $versions = [Target::Latest->value => $latest];
        if (null !== $base) {
            $versions[Target::Minor->value] = $this->best($dependency->name, '^'.$base, $stability);
            $versions[Target::Patch->value] = $this->best($dependency->name, $this->patchConstraint($base), $stability);
        }
        $versions[Target::Semver->value] = $this->best($dependency->name, $dependency->constraint, $stability);

        $candidates = [];
        foreach ($versions as $key => $package) {
            if (null === $package || (null !== $base && Comparator::lessThan($package->getVersion(), $base))) {
                continue;
            }
            $constraint = ConstraintUpgrader::upgrade($dependency->constraint, $package->getPrettyVersion(), $widen);
            if (null === $constraint || $constraint === $dependency->constraint) {
                continue;
            }
            $candidates[$package->getVersion()] ??= [
                'targets' => [],
                'candidate' => new Candidate(
                    ltrim($package->getPrettyVersion(), 'v'),
                    $constraint,
                    Level::between($base ?? $package->getVersion(), $package->getVersion()),
                    $package->getReleaseDate(),
                ),
            ];
            $candidates[$package->getVersion()]['targets'][] = $key;
        }
        if ([] === $candidates) {
            return [null, $hold];
        }

        uksort($candidates, static fn (string $a, string $b): int => version_compare($a, $b));
        $list = array_values($candidates);
        $chosen = null;
        foreach ($list as $index => $entry) {
            if (\in_array($target->value, $entry['targets'], true)) {
                $chosen = $index;
            }
        }
        if (null === $chosen) {
            return [null, $hold];
        }

        return [
            new Upgrade(
                $dependency->name,
                $dependency->dev,
                $dependency->constraint,
                null !== $installed ? ltrim($installed->getPrettyVersion(), 'v') : null,
                array_column($list, 'candidate'),
                $chosen,
            ),
            $hold,
        ];
    }

    private function best(string $name, ?string $constraint, string $stability): ?PackageInterface
    {
        $package = $this->selector->findBestCandidate($name, $constraint, $stability, $this->platformRequirementFilter, 0, null, false);
        while ($package instanceof AliasPackage) {
            $package = $package->getAliasOf();
        }

        return false === $package ? null : $package;
    }

    /**
     * Reports a newer release than the last lookup found, preferring the one the cooldown lets through first like "composer outdated" does.
     */
    private function hold(string $name, ?PackageInterface $latest): ?Hold
    {
        $first = null;
        foreach ($this->selector->getWithheldCandidates() as $withheld) {
            if ($this->isNewer($withheld['package'], $latest) && (null === $first || $withheld['releaseDate'] < $first['releaseDate'])) {
                $first = $withheld;
            }
        }
        if (null !== $first && null !== $this->cooldown) {
            return new Hold(
                $name,
                ltrim($first['package']->getPrettyVersion(), 'v'),
                \sprintf('was released %s and is in the cooldown for another %s', $first['releaseDate']->format('Y-m-d'), $this->cooldown->formatTimeUntilAvailable($first['releaseDate'], new \DateTimeImmutable())),
            );
        }

        foreach ($this->selector->getPlatformRejectedCandidates() as $package) {
            if ($this->isNewer($package, $latest)) {
                return new Hold($name, ltrim($package->getPrettyVersion(), 'v'), $this->unmetPlatformRequirement($package));
            }
        }

        return null;
    }

    private function isNewer(PackageInterface $package, ?PackageInterface $than): bool
    {
        return null === $than || Comparator::greaterThan($package->getVersion(), $than->getVersion());
    }

    private function unmetPlatformRequirement(PackageInterface $package): string
    {
        foreach ($package->getRequires() as $link) {
            if (!PlatformRepository::isPlatformPackage($link->getTarget()) || $this->platformRequirementFilter->isIgnored($link->getTarget())) {
                continue;
            }
            $provided = $this->platform->findPackages($link->getTarget());
            foreach ($provided as $platformPackage) {
                if ($link->getConstraint()->matches(new Constraint('==', $platformPackage->getVersion()))) {
                    continue 2;
                }
            }

            return \sprintf('requires %s %s', $link->getTarget(), $link->getPrettyConstraint());
        }

        return 'has platform requirements this platform does not meet';
    }

    private function preferredStability(string $name, ?PackageInterface $installed): string
    {
        $root = $this->composer->getPackage();
        if ($root->getPreferStable()) {
            return $installed?->getStability() ?? 'stable';
        }

        $flags = $root->getStabilityFlags();

        return isset($flags[$name]) ? (string) array_search($flags[$name], BasePackage::STABILITIES, true) : $root->getMinimumStability();
    }

    private function lowerBound(string $constraint): ?string
    {
        try {
            $bound = $this->parser->parseConstraints($constraint)->getLowerBound();
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $bound->isZero() ? null : Preg::replace('{-dev$}', '', $bound->getVersion());
    }

    /**
     * The same patch-level constraint "composer outdated --patch-only" uses.
     */
    private function patchConstraint(string $version): string
    {
        $trimmed = Preg::replace('{(\.0)+$}D', '', $version);
        $parts = str_starts_with($trimmed, '0') ? 4 : 3;
        while (substr_count($trimmed, '.') + 1 < $parts) {
            $trimmed .= '.0';
        }

        return '~'.$trimmed;
    }
}
