<?php
declare(strict_types=1);

namespace Elephant\Tasks;

use Closure;
use Elephant\ElephantException;
use Elephant\Future\Awaitable;
use Elephant\Future\Cancellable;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Loop\EventLoop;
use SplQueue;

use function Elephant\Flow\async;

/**
 * Starts closure tasks on the next loop tick, never running more than the limit at once.
 *
 * The limit can be changed until the tasks start; afterwards it is fixed.
 * A freed slot is refilled one tick later, so whoever reacts to the finished task can stop the queue first.
 *
 * @internal
 */
final class Throttle
{
    /**
     * Maximum number of tasks running at once, or null for no limit.
     */
    private ?int $limit = null;

    private bool $started = false;

    private bool $stopped = false;

    /**
     * Whether the group contains awaitables that were already running and cannot be held back.
     */
    private bool $adopted = false;

    private int $running = 0;

    /**
     * Running tasks that can be stopped, such as HTTP requests and closure tasks.
     *
     * @var list<Cancellable>
     */
    private array $cancellables = [];

    /**
     * Tasks waiting for a free slot.
     *
     * @var SplQueue<Closure(): void>
     */
    private readonly SplQueue $waiting;

    /**
     * @param EventLoop $loop Loop that starts the tasks and settles their futures.
     */
    public function __construct(
        private readonly EventLoop $loop,
    ) {
        $this->waiting = new SplQueue();

        $loop->defer(function (): void {
            $this->started = true;
            $this->next();
        });
    }

    /**
     * Sets how many tasks may run at the same time.
     *
     * @throws ElephantException When the limit is lower than one, or when the tasks have already started or some of them were running beforehand.
     */
    public function limit(int $concurrency): void
    {
        if ($concurrency < 1) {
            throw new ElephantException('The concurrency limit must be at least one.');
        }

        if ($this->adopted) {
            throw new ElephantException('Only closure tasks can be limited: awaitables such as Http::get() are already running. Wrap them in closures, for example fn (): Response => Http::get($url)->await().');
        }

        if ($this->started) {
            throw new ElephantException('The concurrency limit must be set before the tasks start.');
        }

        $this->limit = $concurrency;
    }

    /**
     * Accepts a task that is already running, which makes the group impossible to limit.
     *
     * Tasks that can be cancelled are cancelled when the group stops.
     *
     * @template T
     *
     * @param Awaitable<T> $task
     *
     * @return Future<T>
     */
    public function adopt(Awaitable $task): Future
    {
        $this->adopted = true;

        if ($task instanceof Cancellable) {
            $this->cancellables[] = $task;
        }

        return $task->future();
    }

    /**
     * Queues a task and returns a future for its result.
     *
     * @template T
     *
     * @param Closure(): T $task
     *
     * @return Future<T>
     */
    public function schedule(Closure $task): Future
    {
        $deferred = new Deferred($this->loop);

        $this->waiting->enqueue(function () use ($task, $deferred): void {
            $run = async($task);
            $this->cancellables[] = $run;
            $future = $run->future();

            $future->onSettle(function () use ($future, $deferred): void {
                $this->running--;
                self::forward($future, $deferred);

                $this->loop->defer(function (): void {
                    $this->next();
                });
            });
        });

        return $deferred->future();
    }

    /**
     * Drops every task that has not started yet and cancels running tasks that can be cancelled.
     */
    public function stop(): void
    {
        $this->stopped = true;

        foreach ($this->cancellables as $task) {
            $task->cancel();
        }
    }

    /**
     * Starts waiting tasks while there are free slots.
     */
    private function next(): void
    {
        while ($this->stopped === false && count($this->waiting) > 0 && $this->hasCapacity()) {
            $this->running++;
            ($this->waiting->dequeue())();
        }
    }

    /**
     * Whether another task may start now.
     */
    private function hasCapacity(): bool
    {
        return $this->limit === null || $this->running < $this->limit;
    }

    /**
     * Settles the deferred the same way the future settled.
     *
     * @template T
     *
     * @param Future<T> $from
     * @param Deferred<T> $to
     */
    private static function forward(Future $from, Deferred $to): void
    {
        if ($from->isFailed()) {
            $to->fail($from->error());

            return;
        }

        $to->complete($from->result());
    }
}
