<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\TestMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(name: 'app:messenger_test')]
final readonly class MessengerTestCommand
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->bus->dispatch(new TestMessage());

        return Command::SUCCESS;
    }
}
