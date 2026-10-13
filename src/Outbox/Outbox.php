<?php

declare(strict_types=1);

namespace EmpireX\Posting\Outbox;

interface Outbox
{
    public function append(Event $event): void;

    /** @return list<Event> */
    public function all(): array;
}
