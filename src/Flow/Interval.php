<?php
declare(strict_types=1);

namespace Elephant\Flow;

use Closure;
use Elephant\Future\Awaitable;
use Elephant\Future\Cancellable;
use Elephant\Future\CancelledException;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Future\TimeoutException;
use Elephant\Loop\EventLoop;
use Elephant\Loop\Timer;
use Throwable;

/**
 * Callback that runs repeatedly, created by every().
 *
 * The next interval starts only after the previous run finished, so runs never overlap.
 * The interval finishes after its last run, fails when a run throws, and can be cancelled at any time.
 *
 * @implements Awaitable<null>
 */
final class Interval implements Awaitable, Cancellable
{
    /**
     * @var Deferred<null>
     */
    private readonly Deferred $finished;

    /**
     * Timer of the next run, or null while a run is in progress.
     */
    private ?Timer $timer = null;

    private int $runs = 0;

    /**
     * @param EventLoop $loop Loop that schedules the runs.
     * @param float $seconds Seconds between the end of one run and the start of the next.
     * @param Closure(): mixed $callback Work to repeat; it runs in its own task, so it may await.
     * @param int|null $times How many times to run, or null to run until cancelled.
     */
    public function __construct(
        private readonly EventLoop $loop,
        private readonly float $seconds,
        private readonly Closure $callback,
        private readonly ?int $times,
    ) {
        $this->finished = new Deferred($loop);
    }

    /**
     * Schedules the first run.
     *
     * @return $this
     */
    public function start(): self
    {
        $this->schedule();

        return $this;
    }

    /**
     * Stops the interval; a run already in progress finishes, but no further run starts.
     */
    public function cancel(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancel($this->timer);
            $this->timer = null;
        }

        $this->finished->cancel();
    }

    /**
     * Waits until the interval has run the given number of times.
     *
     * @param float|null $timeout Seconds to wait at most; when they run out the interval is cancelled.
     *
     * @throws CancelledException When the interval was cancelled.
     * @throws TimeoutException When the timeout runs out first.
     * @throws Throwable The error thrown by a run.
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
        return $this->finished->future();
    }

    /**
     * Schedules the next run after the interval.
     */
    private function schedule(): void
    {
        $this->timer = $this->loop->delay($this->seconds, function (): void {
            $this->timer = null;
            $this->run();
        });
    }

    /**
     * Starts one run in its own task, skipping it when the interval was cancelled before the task began.
     */
    private function run(): void
    {
        $this->runs++;

        $run = async(function (): mixed {
            if ($this->finished->future()->isSettled()) {
                return null;
            }

            return ($this->callback)();
        })->future();

        $run->onSettle(function () use ($run): void {
            $this->settled($run);
        });
    }

    /**
     * Finishes, fails or schedules the next run once a run has settled.
     *
     * @param Future<mixed> $run
     */
    private function settled(Future $run): void
    {
        if ($this->finished->future()->isSettled()) {
            return;
        }

        if ($run->isFailed()) {
            $this->finished->fail($run->error());

            return;
        }

        if ($this->runs === $this->times) {
            $this->finished->complete();

            return;
        }

        $this->schedule();
    }
}
