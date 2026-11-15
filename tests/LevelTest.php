<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tests;

use JackWorman\ComposerCheckUpdates\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LevelTest extends TestCase
{
    /**
     * @return iterable<array{string, string, Level}>
     */
    public static function provideLevels(): iterable
    {
        yield ['1.2.3.0', '2.0.0.0', Level::Major];
        yield ['1.2.3.0', '1.3.0.0', Level::Minor];
        yield ['1.2.3.0', '1.2.4.0', Level::Patch];
        yield ['1.2.3.0', '1.2.3.1', Level::Patch];
        yield ['1.2.3.0', '1.2.3.0', Level::Bump];
        yield ['1.2.3.0-RC1', '1.2.3.0', Level::Patch];
        yield ['0.1.2.0', '0.2.0.0', Level::Major];
        yield ['0.1.2.0', '0.1.3.0', Level::Patch];
        yield ['0.0.1.0', '0.0.2.0', Level::Major];
    }

    #[DataProvider('provideLevels')]
    public function testBetween(string $from, string $to, Level $expected): void
    {
        self::assertSame($expected, Level::between($from, $to));
    }
}
