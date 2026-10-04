<?php
declare(strict_types=1);

namespace Elephant\Loop;

use Closure;
use Elephant\ElephantException;
use SplQueue;
use Symfony\Component\Clock\ClockInterface;

/**
 * Single-threaded scheduler that drives deferred callbacks, timers and polled sources.
 */
final class EventLoop
{
    /**
     * Seconds each source may block when several sources are active.
     */
    private const SLICE = 0.001;

    /**
     * @var SplQueue<Closure(): void>
     */
    private readonly SplQueue $queue;

    /**
     * Pending timers, ordered by due time.
     *
     * @var list<Timer>
     */
    private array $timers = [];

    /**
     * @var list<Source>
     */
    private array $sources = [];

    /**
     * @param ClockInterface $clock Clock used to measure and wait for timers.
     */
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
        $this->queue = new SplQueue();
    }

    /**
     * Registers a source that is polled whenever the loop is idle.
     */
    public function attach(Source $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * Schedules a callback for the next tick.
     *
     * @param Closure(): void $callback
     */
    public function defer(Closure $callback): void
    {
        $this->queue->enqueue($callback);
    }

    /**
     * Schedules a callback to run after the given number of seconds.
     *
     * @param Closure(): void $callback
     *
     * @return Timer The scheduled timer, which can be passed to cancel().
     */
    public function delay(float $seconds, Closure $callback): Timer
    {
        $timer = new Timer($this->now() + max(0.0, $seconds), $callback);
        $this->timers[] = $timer;

        usort($this->timers, static fn (Timer $a, Timer $b): int => $a->at <=> $b->at);

        return $timer;
    }

    /**
     * Removes a timer that has not fired yet, so it never runs and no longer keeps the loop alive.
     */
    public function cancel(Timer $timer): void
    {
        $this->timers = array_values(array_filter($this->timers, static fn (Timer $scheduled): bool => $scheduled !== $timer));
    }

    /**
     * Runs until there are no callbacks, timers or active sources left.
     */
    public function run(): void
    {
        do {
            $active = $this->tick();
        } while ($active);
    }

    /**
     * Runs until the condition holds.
     *
     * @param Closure(): bool $condition
     *
     * @throws ElephantException When the loop runs out of work before the condition holds.
     */
    public function runUntil(Closure $condition): void
    {
        while ($condition() === false) {
            if ($this->tick() === false) {
                throw new ElephantException('The awaited future can never settle: the event loop has no pending work.');
            }
        }
    }

    /**
     * Performs one iteration of the loop.
     *
     * @return bool Whether any work remains.
     */
    private function tick(): bool
    {
        $pending = count($this->queue);

        if ($pending > 0) {
            for ($i = 0; $i < $pending; $i++) {
                $callback = $this->queue->dequeue();
                $callback();
            }

            return true;
        }

        $sources = array_filter($this->sources, static fn (Source $source): bool => $source->isActive());

        if ($this->timers === [] && $sources === []) {
            return false;
        }

        if ($sources === []) {
            $this->clock->sleep($this->untilNextTimer());
        }

        $timeout = $this->timeout(count($sources));

        foreach ($sources as $source) {
            $source->poll($timeout);
        }

        $this->fireDueTimers();

        return true;
    }

    /**
     * Moves every due timer callback into the queue.
     */
    private function fireDueTimers(): void
    {
        $now = $this->now();

        while ($this->timers !== [] && $this->timers[0]->at <= $now) {
            $timer = array_shift($this->timers);
            $this->queue->enqueue($timer->callback);
        }
    }

    /**
     * Returns how long each source may block, or null to block until something happens.
     *
     * With several active sources each one gets a short slice, so no source waits forever while another has work.
     */
    private function timeout(int $sources): ?float
    {
        $slice = null;

        if ($sources > 1) {
            $slice = self::SLICE;
        }

        if ($this->timers === []) {
            return $slice;
        }

        return min($slice ?? INF, $this->untilNextTimer());
    }

    /**
     * Returns the seconds left until the earliest timer is due.
     */
    private function untilNextTimer(): float
    {
        return max(0.0, $this->timers[0]->at - $this->now());
    }

    /**
     * Returns the current clock time in seconds.
     */
    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
