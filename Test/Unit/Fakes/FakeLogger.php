<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Rumvision\Test\Unit\Fakes;

use Psr\Log\AbstractLogger;

class FakeLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $messages = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string)$message;
    }

    /**
     * @return list<string>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
