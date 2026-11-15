<?php

declare(strict_types=1);

namespace JackWorman\ComposerCheckUpdates\Tui;

use JackWorman\ComposerCheckUpdates\Report;
use JackWorman\ComposerCheckUpdates\Upgrade;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;

final readonly class UpgradePicker
{
    public function __construct(
        private TerminalInterface $terminal = new Terminal(),
    ) {
    }

    /**
     * @return list<Upgrade>|null null when the user cancelled
     */
    public function pick(Report $report): ?array
    {
        $tui = new Tui(terminal: $this->terminal);
        $list = new UpgradeListWidget($report->upgrades, $report->holds);
        $list->onSubmit(static fn () => $tui->stop());
        $list->onCancel(static fn () => $tui->stop());
        $tui->add($list);
        $tui->setFocus($list);
        $tui->run();

        return $list->wasConfirmed() ? $list->getChosen() : null;
    }
}
