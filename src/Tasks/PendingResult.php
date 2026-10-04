<?php
declare(strict_types=1);

namespace Elephant\Tasks;

use Closure;
use Elephant\Future\Awaitable;
use Elephant\Future\Cancellable;
use Elephant\Future\CancelledException;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Future\TimeoutException;
use Throwable;

use function Elephant\Flow\await;

/**
 * Single result decided by a group of competing tasks, such as Tasks::race() and Tasks::any().
 *
 * Once the result is decided the remaining tasks are stopped. Cancelling it, directly or through a timeout,
 * stops every task that is still running.
 *
 * @template-covariant T
 *
 * @implements Awaitable<T>
 */
final class PendingResult implements Awaitable, Cancellable
{
    /**
     * @param Deferred<T> $deferred Settles with the result of the group.
     * @param Throttle $throttle Starts the closure tasks of the group.
     */
    public function __construct(
        private readonly Deferred $deferred,
        private readonly Throttle $throttle,
    ) {
    }

    /**
     * Stops the group: queued tasks never start, running tasks are cancelled and running HTTP requests are aborted.
     *
     * Awaiting the result afterwards throws a CancelledException.
     */
    public function cancel(): void
    {
        $this->throttle->stop();
        $this->deferred->cancel();
    }

    /**
     * Waits for the result.
     *
     * @param float|null $timeout Seconds to wait at most; when they run out every task still running is cancelled.
     *
     * @return T
     *
     * @throws TimeoutException When the timeout runs out first.
     * @throws CancelledException When the group was cancelled.
     * @throws Throwable The error that decided the group.
     */
    public function await(?float $timeout = null): mixed
    {
        return await($this, $timeout);
    }

    /**
     * Recovers from errors whose type matches the handler's first parameter.
     *
     * @template E of Throwable
     * @template R
     *
     * @param Closure(E): R $handler
     *
     * @return Future<T|R>
     */
    public function exception(Closure $handler): Future
    {
        return $this->deferred->future()->exception($handler);
    }

    /**
     * @return Future<T>
     */
    public function future(): Future
    {
        return $this->deferred->future();
    }
}
