<?php
declare(strict_types=1);

namespace Elephant\Flow;

use Elephant\Future\Awaitable;
use Elephant\Future\Cancellable;
use Elephant\Future\CancelledException;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Future\TimeoutException;
use Elephant\Loop\EventLoop;
use Elephant\Loop\Timer;

/**
 * Pause created by delay(): completes once its time has passed.
 *
 * Cancelling it removes its timer from the event loop, so a cancelled pause never keeps the script running.
 * A task awaiting it and an await() with a timeout cancel it the same way.
 *
 * @implements Awaitable<null>
 */
final class Delay implements Awaitable, Cancellable
{
    /**
     * @var Deferred<null>
     */
    private readonly Deferred $deferred;

    private readonly Timer $timer;

    /**
     * @param EventLoop $loop Loop that runs the timer.
     * @param float $seconds Time the pause lasts.
     */
    public function __construct(
        private readonly EventLoop $loop,
        float $seconds,
    ) {
        $this->deferred = new Deferred($loop);

        $this->timer = $loop->delay($seconds, function (): void {
            $this->elapse();
        });
    }

    /**
     * Stops the pause and removes its timer; awaiting it afterwards throws a CancelledException.
     *
     * A pause that has already completed stays as it is.
     */
    public function cancel(): void
    {
        $this->loop->cancel($this->timer);
        $this->deferred->cancel();
    }

    /**
     * Waits until the pause is over.
     *
     * @param float|null $timeout Seconds to wait at most; when they run out the pause is cancelled.
     *
     * @throws CancelledException When the pause was cancelled.
     * @throws TimeoutException When the timeout runs out first.
     */
    public function await(?float $timeout = null): void
    {
        await($this, $timeout);
    }

    /**
     * @return Future<null>
     */
    public function future(): Future
    {
        return $this->deferred->future();
    }

    /**
     * Completes the pause, unless it was cancelled after the timer had already fired.
     */
    private function elapse(): void
    {
        if ($this->deferred->future()->isPending()) {
            $this->deferred->complete();
        }
    }
}
