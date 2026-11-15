<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

use Composer\Pcre\Preg;

enum Level: string
{
    case Bump = 'bump';
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';

    /**
     * Below 1.0 a minor release may break, as Composer's ^0.1 doesn't allow 0.2.
     */
    public static function between(string $fromNormalized, string $toNormalized): self
    {
        $from = self::numbers($fromNormalized);
        $to = self::numbers($toNormalized);
        if ($from === $to) {
            return $fromNormalized === $toNormalized ? self::Bump : self::Patch;
        }

        $index = 0;
        while ($from[$index] === $to[$index]) {
            ++$index;
        }

        return match (true) {
            0 === $index => self::Major,
            1 === $index => 0 === $from[0] ? self::Major : self::Minor,
            default => 0 === $from[0] && 0 === $from[1] ? self::Major : self::Patch,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Bump => 'Installed',
            self::Patch => 'Patch',
            self::Minor => 'Minor',
            self::Major => 'Major',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bump => 'Already installed, only the constraint changes',
            self::Patch => 'Backwards-compatible bug fixes',
            self::Minor => 'Backwards-compatible features',
            self::Major => 'Potentially breaking changes',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Bump => 'gray',
            self::Patch => 'green',
            self::Minor => 'cyan',
            self::Major => 'red',
        };
    }

    /**
     * @return array{int, int, int, int}
     */
    private static function numbers(string $normalized): array
    {
        if (!Preg::isMatchStrictGroups('{^(\d+)\.(\d+)\.(\d+)\.(\d+)}', $normalized, $match)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a normalized version.', $normalized));
        }

        return [(int) $match[1], (int) $match[2], (int) $match[3], (int) $match[4]];
    }
}
