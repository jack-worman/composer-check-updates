<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tests;

use JackWorman\ComposerCheckUpdates\Highlight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HighlightTest extends TestCase
{
    /**
     * @return iterable<array{string, string, array{string, string}}>
     */
    public static function provideSplits(): iterable
    {
        yield ['^1.2.3', '^1.2.4', ['^1.2.', '4']];
        yield ['^1.2.3', '^1.3.0', ['^1.', '3.0']];
        yield ['^1.2.3', '^2.0.0', ['^', '2.0.0']];
        yield ['~1.2', '^1.3', ['', '^1.3']];
        yield ['^1.2', '^1.2', ['^1.2', '']];
    }

    /**
     * @param array{string, string} $expected
     */
    #[DataProvider('provideSplits')]
    public function testSplit(string $from, string $to, array $expected): void
    {
        self::assertSame($expected, Highlight::split($from, $to));
    }
}
