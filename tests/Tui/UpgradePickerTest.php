<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tests\Tui;

use JackWorman\ComposerCheckUpdates\Candidate;
use JackWorman\ComposerCheckUpdates\Level;
use JackWorman\ComposerCheckUpdates\Report;
use JackWorman\ComposerCheckUpdates\Tui\UpgradePicker;
use JackWorman\ComposerCheckUpdates\Upgrade;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

final class UpgradePickerTest extends TestCase
{
    public function testReturnsWhatThePersonPicked(): void
    {
        $terminal = new VirtualTerminal(100, 20);
        EventLoop::delay(0.05, static function () use ($terminal): void {
            $terminal->simulateInput("\e[B");
            $terminal->simulateInput(' ');
            $terminal->simulateInput("\r");
        });

        $chosen = new UpgradePicker($terminal)->pick(self::report());

        self::assertSame(['acme/framework', 'acme/tests'], array_map(static fn (Upgrade $upgrade): string => $upgrade->name, $chosen ?? []));
        self::assertStringContainsString('Choose which packages to upgrade', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    public function testReturnsNullWhenCancelled(): void
    {
        $terminal = new VirtualTerminal(100, 20);
        EventLoop::delay(0.05, static fn () => $terminal->simulateInput("\e"));

        self::assertNull(new UpgradePicker($terminal)->pick(self::report()));
    }

    private static function report(): Report
    {
        return new Report([
            new Upgrade('acme/framework', false, '^1.0.0', '1.0.0', [new Candidate('1.1.0', '^1.1.0', Level::Minor, null)], 0),
            new Upgrade('acme/tests', true, '^9.0.0', '9.0.0', [new Candidate('10.0.0', '^10.0.0', Level::Major, null)], 0),
        ], []);
    }
}
