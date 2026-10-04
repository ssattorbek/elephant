<?php
declare(strict_types=1);

namespace Tests\Support;

use Closure;

use function Elephant\Flow\{await, delay};

final class Gauge
{
    private int $running = 0;

    private int $peak = 0;

    public function task(float $seconds): Closure
    {
        return function () use ($seconds): void {
            $this->running++;
            $this->peak = max($this->peak, $this->running);

            await(delay($seconds));

            $this->running--;
        };
    }

    public function peak(): int
    {
        return $this->peak;
    }
}
