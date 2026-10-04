<?php
declare(strict_types=1);

namespace Elephant\Tasks;

use Closure;
use Elephant\ElephantException;
use Elephant\Future\Awaitable;
use Elephant\Future\Cancellable;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Future\TimeoutException;
use Throwable;

use function Elephant\Flow\await;

/**
 * Group of tasks whose results are awaited together.
 *
 * TShape is the exact shape of the results, such as array{user: Response, count: int}; TKey and TValue
 * summarise it for editors that do not understand shapes.
 *
 * @template TShape of array<array-key, mixed>
 * @template TKey of array-key = key-of<TShape>
 * @template TValue = value-of<TShape>
 *
 * @implements Awaitable<TShape>
 */
final class PendingResults implements Awaitable, Cancellable
{
    /**
     * @param Deferred<TShape> $deferred Settles with the results of the group.
     * @param Throttle $throttle Starts the closure tasks of the group.
     */
    public function __construct(
        private readonly Deferred $deferred,
        private readonly Throttle $throttle,
    ) {
    }

    /**
     * Stops the group: queued tasks never start and running HTTP requests are aborted.
     *
     * Awaiting the group afterwards throws a CancelledException.
     */
    public function cancel(): void
    {
        $this->throttle->stop();
        $this->deferred->cancel();
    }

    /**
     * Runs at most the given number of closure tasks at the same time.
     *
     * Call it right after creating the group, before the tasks start on the next loop tick.
     * Every task must be a closure: awaitables such as Http::get() are already running and cannot be held back,
     * so wrap them in closures, for example fn (): Response => Http::get($url)->await().
     *
     * @return $this
     *
     * @throws ElephantException When the limit is lower than one, or when the tasks have already started or some of them are awaitables.
     */
    public function limit(int $concurrency): self
    {
        $this->throttle->limit($concurrency);

        return $this;
    }

    /**
     * Waits for the results, keyed and ordered like the original tasks.
     *
     * @param float|null $timeout Seconds to wait at most; when they run out the whole group is cancelled.
     *
     * @return Results<TShape, TKey, TValue>
     *
     * @throws TimeoutException When the timeout runs out first.
     * @throws Throwable The first error raised by a task.
     */
    public function await(?float $timeout = null): Results
    {
        return new Results(await($this, $timeout));
    }

    /**
     * Recovers from errors whose type matches the handler's first parameter.
     *
     * @template E of Throwable
     * @template R
     *
     * @param Closure(E): R $handler
     *
     * @return Future<TShape|R>
     */
    public function exception(Closure $handler): Future
    {
        return $this->deferred->future()->exception($handler);
    }

    /**
     * @return Future<TShape>
     */
    public function future(): Future
    {
        return $this->deferred->future();
    }
}
