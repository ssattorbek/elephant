<?php
declare(strict_types=1);

namespace Elephant\Tasks;

use Closure;
use Elephant\ElephantException;
use Elephant\Future\Awaitable;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Loop\EventLoop;
use ReflectionFunction;

/**
 * Combines many tasks into one, in the spirit of JavaScript's Promise.all() family.
 *
 * A task is either a closure, which is started on the next loop tick, or an awaitable that is already running.
 * Only closure tasks can be held back by a concurrency limit.
 */
final class Combinator
{
    /**
     * Number of parameters a map() callback declares when it wants the item's key next to the item.
     */
    private const KEYED_PARAMETERS = 2;

    /**
     * @param EventLoop $loop Loop that settles the combined futures.
     */
    public function __construct(
        private readonly EventLoop $loop,
    ) {
    }

    /**
     * Waits for every task and fails as soon as one of them fails.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, (Closure(): TValue)|Awaitable<TValue>> $tasks
     *
     * @return PendingResults<array<TKey, TValue>, TKey, TValue>
     */
    public function all(array $tasks): PendingResults
    {
        $throttle = new Throttle($this->loop);
        $futures = $this->futures($tasks, $throttle);

        /** @var Deferred<array<TKey, TValue>> $deferred */
        $deferred = new Deferred($this->loop);

        self::combine($deferred, $futures, static function (Deferred $deferred) use ($futures, $throttle): void {
            foreach ($futures as $future) {
                if ($future->isFailed()) {
                    $throttle->stop();
                    $deferred->fail($future->error());

                    return;
                }
            }

            if (self::allSettled($futures)) {
                $deferred->complete(array_map(static fn (Future $future): mixed => $future->result(), $futures));
            }
        });

        if ($futures === []) {
            $deferred->complete([]);
        }

        return new PendingResults($deferred, $throttle);
    }

    /**
     * Runs the callback for every item as its own task and waits for all results, failing as soon as one fails.
     *
     * The callback receives the item and, when it is user-defined and declares a second parameter, the item's key.
     * Built-in functions such as trim(...) only receive the item, so their optional parameters keep their defaults.
     *
     * @template TKey of array-key
     * @template TItem
     * @template TValue
     *
     * @param array<TKey, TItem> $items
     * @param (Closure(TItem): TValue)|(Closure(TItem, TKey): TValue) $callback
     *
     * @return PendingResults<array<TKey, TValue>, TKey, TValue>
     */
    public function map(array $items, Closure $callback): PendingResults
    {
        $withKey = self::takesKey($callback);
        $tasks = [];

        foreach ($items as $key => $item) {
            $tasks[$key] = self::task($callback, $item, $key, $withKey);
        }

        return $this->all($tasks);
    }

    /**
     * Waits for every task and reports each outcome, never failing itself.
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, (Closure(): TValue)|Awaitable<TValue>> $tasks
     *
     * @return PendingResults<array<TKey, Result<TValue>>, TKey, Result<TValue>>
     */
    public function settle(array $tasks): PendingResults
    {
        $throttle = new Throttle($this->loop);
        $futures = $this->futures($tasks, $throttle);

        /** @var Deferred<array<TKey, Result<TValue>>> $deferred */
        $deferred = new Deferred($this->loop);

        self::combine($deferred, $futures, static function (Deferred $deferred) use ($futures): void {
            if (self::allSettled($futures)) {
                $deferred->complete(array_map(static fn (Future $future): Result => Result::from($future), $futures));
            }
        });

        if ($futures === []) {
            $deferred->complete([]);
        }

        return new PendingResults($deferred, $throttle);
    }

    /**
     * Settles like the first task to finish, whether it succeeds or fails.
     *
     * The other tasks are stopped once the race is decided, or when the race is cancelled or times out.
     *
     * @template TValue
     *
     * @param array<array-key, (Closure(): TValue)|Awaitable<TValue>> $tasks
     *
     * @return PendingResult<TValue>
     *
     * @throws ElephantException When no task is given.
     */
    public function race(array $tasks): PendingResult
    {
        $throttle = new Throttle($this->loop);
        $futures = $this->futures($this->required($tasks), $throttle);

        /** @var Deferred<TValue> $deferred */
        $deferred = new Deferred($this->loop);

        self::combine($deferred, $futures, static function (Deferred $deferred, Future $settled) use ($throttle): void {
            $throttle->stop();

            if ($settled->isFailed()) {
                $deferred->fail($settled->error());

                return;
            }

            $deferred->complete($settled->result());
        });

        return new PendingResult($deferred, $throttle);
    }

    /**
     * Completes with the first successful task, or fails with an AggregateException when all fail.
     *
     * The other tasks are stopped once a task succeeds, or when the result is cancelled or times out.
     *
     * @template TValue
     *
     * @param array<array-key, (Closure(): TValue)|Awaitable<TValue>> $tasks
     *
     * @return PendingResult<TValue>
     *
     * @throws ElephantException When no task is given.
     */
    public function any(array $tasks): PendingResult
    {
        $throttle = new Throttle($this->loop);
        $futures = $this->futures($this->required($tasks), $throttle);

        /** @var Deferred<TValue> $deferred */
        $deferred = new Deferred($this->loop);

        self::combine($deferred, $futures, static function (Deferred $deferred) use ($futures, $throttle): void {
            foreach ($futures as $future) {
                if ($future->isCompleted()) {
                    $throttle->stop();
                    $deferred->complete($future->result());

                    return;
                }
            }

            if (self::allSettled($futures)) {
                $deferred->fail(new AggregateException(array_map(static fn (Future $future): mixed => $future->error(), $futures)));
            }
        });

        return new PendingResult($deferred, $throttle);
    }

    /**
     * Calls the resolver each time a task settles, until the deferred settles.
     *
     * @template TResult
     *
     * @param Deferred<TResult> $deferred
     * @param array<array-key, Future<mixed>> $futures
     * @param Closure(Deferred<TResult>, Future<mixed>): void $resolve
     */
    private static function combine(Deferred $deferred, array $futures, Closure $resolve): void
    {
        $combined = $deferred->future();

        foreach ($futures as $future) {
            $future->onSettle(static function () use ($deferred, $combined, $resolve, $future): void {
                if ($combined->isPending()) {
                    $resolve($deferred, $future);
                }
            });
        }
    }

    /**
     * Wraps one call of a map() callback into a task.
     *
     * @template TItem
     * @template TKey of array-key
     * @template TValue
     *
     * @param (Closure(TItem): TValue)|(Closure(TItem, TKey): TValue) $callback
     * @param TItem $item
     * @param TKey $key
     *
     * @return Closure(): TValue
     */
    private static function task(Closure $callback, mixed $item, int|string $key, bool $withKey): Closure
    {
        if ($withKey) {
            return static fn (): mixed => $callback($item, $key);
        }

        return static fn (): mixed => $callback($item);
    }

    /**
     * Whether a map() callback wants the item's key: only user-defined callbacks that declare a second parameter do.
     */
    private static function takesKey(Closure $callback): bool
    {
        $reflection = new ReflectionFunction($callback);

        return $reflection->isUserDefined() && $reflection->getNumberOfParameters() >= self::KEYED_PARAMETERS;
    }

    /**
     * Rejects an empty list of tasks.
     *
     * @template T of array<array-key, mixed>
     *
     * @param T $tasks
     *
     * @return T
     *
     * @throws ElephantException When no task is given.
     */
    private function required(array $tasks): array
    {
        if ($tasks === []) {
            throw new ElephantException('At least one task is required.');
        }

        return $tasks;
    }

    /**
     * Converts every task to a future, keeping the keys.
     *
     * @param array<array-key, mixed> $tasks
     *
     * @return array<array-key, Future<mixed>>
     */
    private function futures(array $tasks, Throttle $throttle): array
    {
        return array_map(static fn (mixed $task): Future => self::future($task, $throttle), $tasks);
    }

    /**
     * Schedules a closure task or unwraps an awaitable.
     *
     * @return Future<mixed>
     *
     * @throws ElephantException When the task is neither a closure nor an awaitable.
     */
    private static function future(mixed $task, Throttle $throttle): Future
    {
        if ($task instanceof Closure) {
            return $throttle->schedule(static fn (): mixed => $task());
        }

        if ($task instanceof Awaitable) {
            return $throttle->adopt($task);
        }

        throw new ElephantException('A task must be a closure or an awaitable.');
    }

    /**
     * Whether none of the futures is pending.
     *
     * @param array<array-key, Future<mixed>> $futures
     */
    private static function allSettled(array $futures): bool
    {
        foreach ($futures as $future) {
            if ($future->isPending()) {
                return false;
            }
        }

        return true;
    }
}
