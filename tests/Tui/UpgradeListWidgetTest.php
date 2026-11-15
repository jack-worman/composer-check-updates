<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tests\Tui;

use JackWorman\ComposerCheckUpdates\Candidate;
use JackWorman\ComposerCheckUpdates\Hold;
use JackWorman\ComposerCheckUpdates\Level;
use JackWorman\ComposerCheckUpdates\Tui\UpgradeListWidget;
use JackWorman\ComposerCheckUpdates\Upgrade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

final class UpgradeListWidgetTest extends TestCase
{
    private const string DOWN = "\e[B";
    private const string RIGHT = "\e[C";
    private const string LEFT = "\e[D";

    public function testGroupsRowsByLevelAndLeavesMajorsUnselected(): void
    {
        $widget = new UpgradeListWidget(self::upgrades(), [new Hold('acme/framework', '3.0.0', 'is in the cooldown')]);

        self::assertSame([
            'Choose which packages to upgrade',
            '↑↓ move · space select · ←→ version · a all · enter upgrade · esc cancel',
            '',
            'Patch  Backwards-compatible bug fixes',
            '❯ ◉ acme/logger       ^1.4.0  →  ^1.4.2      2026-10-01',
            '',
            'Major  Potentially breaking changes',
            '  ◯ acme/framework    ^1.0.0  →  ^2.1.0  ‹   2026-10-20',
            '  ◯ acme/tests (dev)  ^9.0.0  →  ^10.1.0     ',
            '',
            '',
            '1 of 3 selected · 1 newer release held back',
        ], self::render($widget));
    }

    public function testShowsTheHeldReleaseOfTheRowUnderTheCursor(): void
    {
        $widget = new UpgradeListWidget(self::upgrades(), [new Hold('acme/framework', '3.0.0', 'is in the cooldown')]);

        $widget->handleInput(self::DOWN);

        self::assertContains('Held back: acme/framework 3.0.0 is in the cooldown', self::render($widget));
    }

    public function testPicksRowsAndVersions(): void
    {
        $widget = new UpgradeListWidget(self::upgrades());

        $widget->handleInput(' ');
        $widget->handleInput(self::RIGHT);
        $widget->handleInput(self::DOWN);
        $widget->handleInput(self::LEFT);
        $widget->handleInput(self::LEFT);
        $widget->handleInput(self::DOWN);
        $widget->handleInput(' ');
        $widget->handleInput("\r");

        self::assertTrue($widget->wasConfirmed());
        self::assertSame(
            ['acme/framework' => '^1.1.1', 'acme/tests' => '^10.1.0'],
            array_column(array_map(static fn (Upgrade $upgrade): array => [$upgrade->name, $upgrade->target()->constraint], $widget->getChosen()), 1, 0),
        );
    }

    public function testTogglesEverything(): void
    {
        $widget = new UpgradeListWidget(self::upgrades());

        $widget->handleInput('a');
        self::assertCount(3, $widget->getChosen());

        $widget->handleInput('a');
        self::assertSame([], $widget->getChosen());
    }

    public function testCancel(): void
    {
        $widget = new UpgradeListWidget(self::upgrades());
        $cancelled = false;
        $widget->onCancel(static function () use (&$cancelled): void {
            $cancelled = true;
        });

        $widget->handleInput("\e");

        self::assertTrue($cancelled);
        self::assertFalse($widget->wasConfirmed());
    }

    public function testScrollsLongLists(): void
    {
        $upgrades = [];
        for ($i = 0; $i < 30; ++$i) {
            $upgrades[] = new Upgrade(\sprintf('acme/package-%02d', $i), false, '^1.0.0', '1.0.0', [new Candidate('1.0.1', '^1.0.1', Level::Patch, null)], 0);
        }
        $widget = new UpgradeListWidget($upgrades);
        for ($i = 0; $i < 20; ++$i) {
            $widget->handleInput(self::DOWN);
        }

        $lines = self::render($widget, 15);

        self::assertCount(15, $lines);
        self::assertContains('❯ ◉ acme/package-20  ^1.0.0  →  ^1.0.1', array_map(rtrim(...), $lines));
        self::assertContains('  (21/30)', $lines);
    }

    /**
     * @return list<Upgrade>
     */
    private static function upgrades(): array
    {
        return [
            new Upgrade('acme/framework', false, '^1.0.0', '1.0.0', [
                new Candidate('1.1.1', '^1.1.1', Level::Minor, new \DateTimeImmutable('2026-09-01')),
                new Candidate('2.1.0', '^2.1.0', Level::Major, new \DateTimeImmutable('2026-10-20')),
            ], 1),
            new Upgrade('acme/logger', false, '^1.4.0', '1.4.0', [new Candidate('1.4.2', '^1.4.2', Level::Patch, new \DateTimeImmutable('2026-10-01'))], 0),
            new Upgrade('acme/tests', true, '^9.0.0', '9.0.0', [new Candidate('10.1.0', '^10.1.0', Level::Major, null)], 0),
        ];
    }

    /**
     * @return list<string>
     */
    private static function render(UpgradeListWidget $widget, int $rows = 40): array
    {
        return array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(100, $rows)));
    }
}
