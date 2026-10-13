<?php

declare(strict_types=1);

namespace EmpireX\Posting\Outbox;

final class MemoryOutbox implements Outbox
{
    /** @var list<Event> */
    private array $events = [];

    public function append(Event $event): void
    {
        $this->events[] = $event;
    }

    public function all(): array
    {
        return $this->events;
    }
}
