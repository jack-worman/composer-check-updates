<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tui;

use JackWorman\ComposerCheckUpdates\Highlight;
use JackWorman\ComposerCheckUpdates\Hold;
use JackWorman\ComposerCheckUpdates\Level;
use JackWorman\ComposerCheckUpdates\Upgrade;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * A checklist of upgrades, grouped by how risky they are, where each row can switch between the versions it offers.
 */
final class UpgradeListWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;

    private const array ANSI = ['red' => '31', 'green' => '32', 'cyan' => '36', 'gray' => '90', 'bold' => '1', 'dim' => '2'];

    /** @var list<Upgrade> in display order */
    private array $rows;

    /** @var array<int, int> the candidate each row shows */
    private array $choices;

    /** @var array<int, bool> */
    private array $selected;

    /** @var list<int> the index each row had in the list it was given */
    private array $order;

    private int $cursor = 0;
    private bool $confirmed = false;

    /** @var array<string, Hold> */
    private readonly array $holds;

    /**
     * @param list<Upgrade> $upgrades
     * @param list<Hold>    $holds
     */
    public function __construct(array $upgrades, array $holds = [])
    {
        $this->holds = array_column($holds, null, 'name');
        $order = array_keys($upgrades);
        usort($order, static fn (int $a, int $b): int => [self::rank($upgrades[$a]), $a] <=> [self::rank($upgrades[$b]), $b]);
        $this->order = $order;
        $this->rows = array_map(static fn (int $index): Upgrade => $upgrades[$index], $order);
        $this->choices = array_map(static fn (Upgrade $upgrade): int => $upgrade->chosen, $this->rows);
        $this->selected = array_map(static fn (Upgrade $upgrade): bool => Level::Major !== $upgrade->target()->level, $this->rows);
    }

    public function wasConfirmed(): bool
    {
        return $this->confirmed;
    }

    /**
     * @return list<Upgrade> in the order they were given
     */
    public function getChosen(): array
    {
        $chosen = [];
        foreach ($this->rows as $index => $upgrade) {
            if ($this->selected[$index]) {
                $chosen[$this->order[$index]] = $upgrade->choose($this->choices[$index]);
            }
        }
        ksort($chosen);

        return array_values($chosen);
    }

    /**
     * @param callable(SubmitEvent): void $callback
     *
     * @return $this
     */
    public function onSubmit(callable $callback): static
    {
        return $this->on(SubmitEvent::class, $callback);
    }

    /**
     * @param callable(CancelEvent): void $callback
     *
     * @return $this
     */
    public function onCancel(callable $callback): static
    {
        return $this->on(CancelEvent::class, $callback);
    }

    public function handleInput(string $data): void
    {
        if (null !== $this->onInput && ($this->onInput)($data)) {
            return;
        }

        $keys = $this->getKeybindings();
        $count = \count($this->rows);
        if ($keys->matches($data, 'cancel')) {
            $this->dispatch(new CancelEvent($this));

            return;
        }
        if ($keys->matches($data, 'confirm')) {
            $this->confirmed = true;
            $this->dispatch(new SubmitEvent($this, (string) \count($this->getChosen())));

            return;
        }
        if (0 === $count) {
            return;
        }

        match (true) {
            $keys->matches($data, 'up') => $this->cursor = ($this->cursor + $count - 1) % $count,
            $keys->matches($data, 'down') => $this->cursor = ($this->cursor + 1) % $count,
            $keys->matches($data, 'page_up') => $this->cursor = max(0, $this->cursor - 10),
            $keys->matches($data, 'page_down') => $this->cursor = min($count - 1, $this->cursor + 10),
            $keys->matches($data, 'toggle') => $this->selected[$this->cursor] = !$this->selected[$this->cursor],
            $keys->matches($data, 'all') => $this->selected = array_fill(0, $count, \in_array(false, $this->selected, true)),
            $keys->matches($data, 'older') => $this->switchVersion(-1),
            $keys->matches($data, 'newer') => $this->switchVersion(1),
            default => null,
        };
        $this->invalidate();
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $lines = [
            self::paint('Choose which packages to upgrade', 'bold'),
            self::paint('↑↓ move · space select · ←→ version · a all · enter upgrade · esc cancel', 'dim'),
            '',
        ];

        $footer = [''];
        if ([] !== $this->holds) {
            $hold = $this->holds[$this->rows[$this->cursor]->name ?? ''] ?? null;
            $footer[] = null === $hold ? '' : self::paint(StringUtils::stripControlBytes(\sprintf('Held back: %s %s %s', $hold->name, $hold->version, $hold->reason)), 'dim');
        }
        $footer[] = \sprintf('%d of %d selected', \count(array_filter($this->selected)), \count($this->rows))
            .([] === $this->holds ? '' : self::paint(\sprintf(' · %d newer %s held back', \count($this->holds), 1 === \count($this->holds) ? 'release' : 'releases'), 'dim'));

        $list = $this->listLines();
        $height = max(5, $context->getRows() - \count($lines) - \count($footer));
        if (\count($list) > $height) {
            --$height;
        }
        $cursorLine = (int) array_search($this->cursor, array_column($list, 1), true);
        $start = max(0, min($cursorLine - intdiv($height, 2), \count($list) - $height));
        foreach (\array_slice($list, $start, $height) as [$line]) {
            $lines[] = $line;
        }
        if (\count($list) > $height) {
            $lines[] = self::paint(\sprintf('  (%d/%d)', $this->cursor + 1, \count($this->rows)), 'dim');
        }

        return array_map(
            static fn (string $line): string => AnsiUtils::truncateToWidth($line, $columns, ''),
            [...$lines, ...$footer],
        );
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'up' => [Key::UP, 'k'],
            'down' => [Key::DOWN, 'j'],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'toggle' => [Key::SPACE],
            'older' => [Key::LEFT, 'h'],
            'newer' => [Key::RIGHT, 'l'],
            'all' => ['a'],
            'confirm' => [Key::ENTER],
            'cancel' => [Key::ESCAPE, 'q', 'ctrl+c'],
        ];
    }

    /**
     * @return list<array{string, ?int}> each line with the row it shows
     */
    private function listLines(): array
    {
        $labels = array_map(static fn (Upgrade $upgrade): string => StringUtils::stripControlBytes($upgrade->name.($upgrade->dev ? ' (dev)' : '')), $this->rows);
        $labelWidth = max([0, ...array_map(AnsiUtils::visibleWidth(...), $labels)]);
        $fromWidth = max([0, ...array_map(static fn (Upgrade $upgrade): int => AnsiUtils::visibleWidth($upgrade->constraint), $this->rows)]);
        $toWidth = 0;
        foreach ($this->rows as $upgrade) {
            foreach ($upgrade->candidates as $candidate) {
                $toWidth = max($toWidth, AnsiUtils::visibleWidth($candidate->constraint));
            }
        }

        $lines = [];
        $group = null;
        foreach ($this->rows as $index => $upgrade) {
            $level = $upgrade->target()->level;
            if ($level !== $group) {
                $group = $level;
                if ([] !== $lines) {
                    $lines[] = ['', null];
                }
                $lines[] = [self::paint($level->title(), $level->color(), 'bold').'  '.$level->description(), null];
            }

            $candidate = $upgrade->candidates[$this->choices[$index]];
            [$same, $changed] = Highlight::split($upgrade->constraint, $candidate->constraint);
            $more = (0 < $this->choices[$index] ? '‹' : ' ').($this->choices[$index] < \count($upgrade->candidates) - 1 ? '›' : ' ');
            $line = \sprintf(
                '%s %s %s  %s  →  %s%s %s  %s',
                $index === $this->cursor ? self::paint('❯', 'cyan') : ' ',
                $this->selected[$index] ? self::paint('◉', 'green') : '◯',
                ($index === $this->cursor ? self::paint($labels[$index], 'bold') : $labels[$index]).str_repeat(' ', $labelWidth - AnsiUtils::visibleWidth($labels[$index])),
                StringUtils::stripControlBytes(str_pad($upgrade->constraint, $fromWidth)),
                StringUtils::stripControlBytes($same),
                self::paint(StringUtils::stripControlBytes($changed), $candidate->level->color()),
                str_repeat(' ', $toWidth - AnsiUtils::visibleWidth($candidate->constraint)).self::paint($more, 'dim'),
                self::paint($candidate->releaseDate?->format('Y-m-d') ?? '', 'dim'),
            );
            $lines[] = [$line, $index];
        }

        return $lines;
    }

    private function switchVersion(int $step): void
    {
        $choice = max(0, min(\count($this->rows[$this->cursor]->candidates) - 1, $this->choices[$this->cursor] + $step));
        if ($choice !== $this->choices[$this->cursor]) {
            $this->choices[$this->cursor] = $choice;
            $this->selected[$this->cursor] = true;
        }
    }

    private static function rank(Upgrade $upgrade): int
    {
        return (int) array_search($upgrade->target()->level, Level::cases(), true);
    }

    private static function paint(string $text, string ...$styles): string
    {
        if ('' === $text) {
            return '';
        }
        $codes = implode(';', array_map(static fn (string $style): string => self::ANSI[$style], $styles));

        return "\e[{$codes}m{$text}\e[0m";
    }
}
