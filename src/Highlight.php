<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates;

use Composer\Pcre\Preg;

final class Highlight
{
    /**
     * Splits $to into the part it shares with $from and the part that changed, like npm-check-updates colors it.
     *
     * @return array{string, string}
     */
    public static function split(string $from, string $to): array
    {
        Preg::matchStrictGroups('{^(\D*)(.*)$}s', $from, $fromMatch);
        Preg::matchStrictGroups('{^(\D*)(.*)$}s', $to, $toMatch);
        if ($fromMatch[1] !== $toMatch[1]) {
            return ['', $to];
        }

        $fromParts = explode('.', $fromMatch[2]);
        $toParts = explode('.', $toMatch[2]);
        $same = 0;
        while (isset($fromParts[$same], $toParts[$same]) && $fromParts[$same] === $toParts[$same]) {
            ++$same;
        }
        if ($same === \count($toParts)) {
            return [$to, ''];
        }

        $shared = implode('.', \array_slice($toParts, 0, $same));

        return [$toMatch[1].$shared.(0 === $same ? '' : '.'), implode('.', \array_slice($toParts, $same))];
    }
}
