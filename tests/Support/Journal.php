<?php
declare(strict_types=1);

namespace Tests\Support;

use Closure;

final class Journal
{
    private array $entries = [];

    public function write(string $entry): void
    {
        $this->entries[] = $entry;
    }

    public function record(string $entry): Closure
    {
        return function () use ($entry): void {
            $this->write($entry);
        };
    }

    public function entries(): array
    {
        return $this->entries;
    }
}
