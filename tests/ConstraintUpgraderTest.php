<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tests;

use JackWorman\ComposerCheckUpdates\ConstraintUpgrader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConstraintUpgraderTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function provideUpgrades(): iterable
    {
        yield 'caret' => ['^1.2.3', '3.4.5', '^3.4.5'];
        yield 'caret keeps its precision' => ['^1.2', '3.4.5', '^3.4'];
        yield 'caret on a major' => ['^1', '3.4.5', '^3'];
        yield 'tilde' => ['~1.2.3', '3.4.5', '~3.4.5'];
        yield 'two-part tilde' => ['~1.2', '3.4.5', '~3.4'];
        yield 'exact pin' => ['1.2.3', '3.4.5', '3.4.5'];
        yield 'exact pin with operator' => ['==1.2.3', '3.4.5', '==3.4.5'];
        yield 'lower bound' => ['>=1.2', '3.4.5', '>=3.4'];
        yield 'wildcard' => ['1.2.*', '3.4.5', '3.4.*'];
        yield 'major wildcard' => ['1.*', '3.4.5', '3.*'];
        yield 'x wildcard' => ['1.2.x', '3.4.5', '3.4.x'];
        yield 'v prefix' => ['^v1.2', 'v3.4.5', '^v3.4'];
        yield 'stability flag' => ['^1.2@beta', '3.4.5', '^3.4@beta'];
        yield 'pre-release target keeps every part' => ['^1.2', '3.0.0-RC1', '^3.0.0-RC1'];
        yield 'pre-release constraint to stable' => ['^1.0.0-beta1', '1.2.0', '^1.2.0'];
        yield 'zero major' => ['^0.3', '0.5.1', '^0.5'];
        yield 'bump within the range' => ['^1.2.3', '1.4.0', '^1.4.0'];
        yield 'same at this precision' => ['^1.2', '1.2.7', '^1.2'];
        yield 'or constraint outside the range' => ['^6.4 || ^7.0', '8.1.2', '^8.1'];
        yield 'single bar or constraint' => ['^6.4|^7.0', '8.1.2', '^8.1'];
        yield 'or constraint already allows it' => ['^6.4 || ^7.0', '7.3.0', '^6.4 || ^7.0'];
        yield 'range falls back to caret' => ['>=1.0 <2.0', '3.4.5', '^3.4.5'];
        yield 'upper bound falls back to caret' => ['<2.0', '3.4.5', '^3.4.5'];
        yield 'hyphen range falls back to caret' => ['1.0 - 2.0', '3.4.5', '^3.4.5'];
        yield 'anything stays anything' => ['*', '3.4.5', null];
    }

    #[DataProvider('provideUpgrades')]
    public function testUpgrade(string $constraint, string $version, ?string $expected): void
    {
        self::assertSame($expected, ConstraintUpgrader::upgrade($constraint, $version));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideWidenings(): iterable
    {
        yield 'single constraint' => ['^1.2', '3.4.5', '^1.2 || ^3.4'];
        yield 'or constraint' => ['^6.4 || ^7.0', '8.1.2', '^6.4 || ^7.0 || ^8.1'];
        yield 'keeps the separator' => ['^6.4|^7.0', '8.1.2', '^6.4|^7.0|^8.1'];
        yield 'already allowed' => ['^1.2', '1.4.0', '^1.2'];
    }

    #[DataProvider('provideWidenings')]
    public function testWiden(string $constraint, string $version, string $expected): void
    {
        self::assertSame($expected, ConstraintUpgrader::upgrade($constraint, $version, true));
    }
}
