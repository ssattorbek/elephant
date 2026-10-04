<?php
declare(strict_types=1);

namespace Elephant\Channel;

use Closure;
use Elephant\Future\Awaitable;
use Elephant\Future\Cancellable;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Loop\EventLoop;
use Throwable;

/**
 * A sender or receiver that waits on a channel.
 *
 * Cancelling it hands it back to the channel first, so the channel can forget it while it still waits,
 * or pass on a value it was given but will never read.
 *
 * @internal
 *
 * @template R
 * @template P
 *
 * @implements Awaitable<R>
 */
final class Waiter implements Awaitable, Cancellable
{
    /**
     * @var Deferred<R>
     */
    private readonly Deferred $deferred;

    private bool $cancelled = false;

    /**
     * @param EventLoop $loop Loop that notifies whoever awaits the waiter.
     * @param Closure(Waiter<R, P>): void $withdraw Tells the channel that the waiter was cancelled; called at most once.
     * @param P $payload Value a sender wants to deliver; null for a receiver.
     */
    public function __construct(
        EventLoop $loop,
        private readonly Closure $withdraw,
        private readonly mixed $payload = null,
    ) {
        $this->deferred = new Deferred($loop);
    }

    /**
     * Returns the value the sender wants to deliver.
     *
     * @return P
     */
    public function payload(): mixed
    {
        return $this->payload;
    }

    /**
     * Ends the wait with a value.
     *
     * @param R $value
     */
    public function complete(mixed $value): void
    {
        $this->deferred->complete($value);
    }

    /**
     * Ends the wait with an error.
     */
    public function fail(Throwable $error): void
    {
        $this->deferred->fail($error);
    }

    /**
     * Hands the waiter back to the channel and stops the wait, unless it has already been cancelled.
     */
    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        ($this->withdraw)($this);
        $this->deferred->cancel();
    }

    /**
     * @return Future<R>
     */
    public function future(): Future
    {
        return $this->deferred->future();
    }
}
