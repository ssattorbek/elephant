<?php
declare(strict_types=1);

namespace Elephant\Loop;

use Closure;

/**
 * Callback scheduled to run at a point in time.
 *
 * @internal
 */
final class Timer
{
    /**
     * @param float $at Clock time in seconds when the callback becomes due.
     * @param Closure(): void $callback
     */
    public function __construct(
        public readonly float $at,
        public readonly Closure $callback,
    ) {
    }
}
