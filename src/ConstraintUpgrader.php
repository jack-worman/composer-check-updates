<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

use Composer\Pcre\Preg;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Rewrites a constraint to require a newer version in the constraint's own style:
 * "^1.2" becomes "^3.4", "~1.2.3" becomes "~3.4.5", "1.2.*" becomes "3.4.*" and an exact pin stays exact.
 */
final class ConstraintUpgrader
{
    private const string TERM = '{^(?<operator>\^|~|>=|==?|)\s*(?<prefix>v?)(?<numbers>\d+(?:\.\d+)*)(?<wildcard>\.[*xX])?(?:-?(?:alpha|beta|rc|a|b|dev)[.-]?\d*)?(?<flag>@[A-Za-z]+)?$}i';

    /**
     * Returns null when there's nothing to rewrite or the rewrite wouldn't allow $version.
     *
     * A constraint that already allows several majors with "||" is only rewritten once $version is outside all of them.
     * With $widen, the constraint keeps what it allows and gets the new version appended instead.
     */
    public static function upgrade(string $constraint, string $version, bool $widen = false): ?string
    {
        $constraint = trim($constraint);
        if ('*' === $constraint || !Preg::isMatchStrictGroups('{^v?(?<numbers>\d+(?:\.\d+)*)(?<suffix>.*)$}', $version, $match)) {
            return null;
        }

        $numbers = explode('.', $match['numbers']);
        $suffix = 'stable' === VersionParser::parseStability($version) ? '' : $match['suffix'];

        if (($widen || str_contains($constraint, '|')) && Semver::satisfies($version, $constraint)) {
            return $constraint;
        }

        $last = Preg::replace('{^.*\|\s*}s', '', $constraint);
        $upgraded = self::upgradeTerm($last, $numbers, $suffix) ?? self::caret($numbers, $suffix);
        if ($widen) {
            $upgraded = $constraint.(Preg::isMatch('{\s*\|\|?\s*}', $constraint, $separator) ? $separator[0] : ' || ').$upgraded;
        }

        return Semver::satisfies($version, $upgraded) ? $upgraded : null;
    }

    /**
     * @param list<string> $numbers
     */
    private static function upgradeTerm(string $term, array $numbers, string $suffix): ?string
    {
        if (!Preg::isMatch(self::TERM, $term, $match)) {
            return null;
        }

        $count = \count(explode('.', (string) $match['numbers']));
        $flag = $match['flag'] ?? '';
        if (null !== ($match['wildcard'] ?? null)) {
            if ('' !== $match['operator']) {
                return null;
            }

            return $match['prefix'].implode('.', self::take($numbers, $count)).$match['wildcard'].$flag;
        }

        // A pre-release keeps every part, or "^3.0" would also allow the alphas before it.
        $parts = '' === $suffix ? self::take($numbers, $count) : self::take($numbers, max($count, \count($numbers)));

        return $match['operator'].$match['prefix'].implode('.', $parts).$suffix.$flag;
    }

    /**
     * @param list<string> $numbers
     */
    private static function caret(array $numbers, string $suffix): string
    {
        return '^'.implode('.', self::take($numbers, max(3, '' === $suffix ? 0 : \count($numbers)))).$suffix;
    }

    /**
     * @param list<string> $numbers
     *
     * @return list<string>
     */
    private static function take(array $numbers, int $count): array
    {
        return array_pad(\array_slice($numbers, 0, $count), $count, '0');
    }
}
