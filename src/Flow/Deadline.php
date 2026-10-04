<?php
declare(strict_types=1);

namespace Elephant\Flow;

use Elephant\Future\Awaitable;
use Elephant\Future\Cancellable;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Future\TimeoutException;
use Elephant\Loop\EventLoop;
use Elephant\Loop\Timer;

/**
 * Races an awaitable against a timer: settles like the awaitable, or fails with a TimeoutException when time runs out.
 *
 * When time runs out, the awaitable is cancelled if it can be.
 *
 * @internal
 *
 * @template T
 *
 * @implements Awaitable<T>
 */
final class Deadline implements Awaitable, Cancellable
{
    /**
     * @var Deferred<T>
     */
    private readonly Deferred $deferred;

    private readonly Timer $timer;

    /**
     * @param EventLoop $loop Loop that runs the timer.
     * @param Awaitable<T> $awaitable Work that has to finish in time.
     * @param float $seconds Time the work may take.
     * @param TimeoutException $error Error to fail with, created where await() was called so it points at that line.
     */
    public function __construct(
        private readonly EventLoop $loop,
        private readonly Awaitable $awaitable,
        float $seconds,
        TimeoutException $error,
    ) {
        $this->deferred = new Deferred($loop);

        $this->timer = $loop->delay($seconds, function () use ($error): void {
            $this->expire($error);
        });

        $future = $awaitable->future();

        $future->onSettle(function () use ($future): void {
            $this->settle($future);
        });
    }

    /**
     * Stops the timer and cancels the awaitable.
     */
    public function cancel(): void
    {
        $this->loop->cancel($this->timer);
        $this->stop();
        $this->deferred->cancel();
    }

    /**
     * @return Future<T>
     */
    public function future(): Future
    {
        return $this->deferred->future();
    }

    /**
     * Fails with the timeout and cancels the awaitable, unless it finished in time.
     */
    private function expire(TimeoutException $error): void
    {
        if ($this->deferred->future()->isSettled()) {
            return;
        }

        $this->deferred->fail($error);
        $this->stop();
    }

    /**
     * Settles like the awaitable, unless the timeout came first.
     *
     * @param Future<T> $future
     */
    private function settle(Future $future): void
    {
        $this->loop->cancel($this->timer);

        if ($this->deferred->future()->isSettled()) {
            return;
        }

        if ($future->isFailed()) {
            $this->deferred->fail($future->error());

            return;
        }

        $this->deferred->complete($future->result());
    }

    /**
     * Cancels the awaitable when it can be cancelled.
     */
    private function stop(): void
    {
        if ($this->awaitable instanceof Cancellable) {
            $this->awaitable->cancel();
        }
    }
}
