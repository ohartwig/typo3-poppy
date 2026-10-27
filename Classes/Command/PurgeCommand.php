<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\Poppy\Typo3\Command;

use Koh\Poppy\Server\Storage\PoppyStorage;
use Koh\Poppy\Storage\Dbal\DbalReplayGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Removes expired Sessions, tokens, codes and one-time ids. Every lookup
 * checks expiry itself, so this is housekeeping, not security; run it daily
 * from the scheduler (Execute console commands).
 */
#[AsCommand(name: 'koh:poppy:purge', description: 'Remove expired Poppy Sessions, tokens, codes and replay records.')]
final class PurgeCommand extends Command
{
    public function __construct(
        private readonly PoppyStorage $storage,
        private readonly DbalReplayGuard $replayGuard,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = $this->clock->now();
        $this->storage->purgeExpired($now);
        $this->replayGuard->purgeExpired($now);
        $output->writeln('Expired Poppy records removed.');

        return Command::SUCCESS;
    }
}
