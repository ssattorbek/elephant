<?php
declare(strict_types=1);

namespace Elephant\Http;

use Closure;
use Elephant\Future\Awaitable;
use Elephant\Future\CancelledException;
use Elephant\Future\Cancellable;
use Elephant\Future\Future;
use Elephant\Future\TimeoutException;
use Throwable;

use function Elephant\Flow\await;

/**
 * HTTP request that is in flight.
 *
 * @implements Awaitable<Response>
 */
final class PendingResponse implements Awaitable, Cancellable
{
    /**
     * @param Future<Response> $future
     * @param Closure(): void $cancel Stops the request on the network.
     */
    public function __construct(
        private readonly Future $future,
        private readonly Closure $cancel,
    ) {
    }

    /**
     * Stops the request; awaiting it afterwards throws a CancelledException.
     */
    public function cancel(): void
    {
        ($this->cancel)();
    }

    /**
     * Waits for the response.
     *
     * @param float|null $timeout Seconds to wait at most; when they run out the request is cancelled.
     *
     * @throws ConnectionException When the request fails at the network level.
     * @throws CancelledException When the request was cancelled.
     * @throws TimeoutException When the timeout runs out first.
     */
    public function await(?float $timeout = null): Response
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
     * @return Future<Response|R>
     */
    public function exception(Closure $handler): Future
    {
        return $this->future->exception($handler);
    }

    /**
     * @return Future<Response>
     */
    public function future(): Future
    {
        return $this->future;
    }
}
