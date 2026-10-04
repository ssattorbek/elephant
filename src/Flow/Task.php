<?php
declare(strict_types=1);

namespace Elephant\Flow;

use Closure;
use Elephant\Future\Awaitable;
use Elephant\Future\Cancellable;
use Elephant\Future\CancelledException;
use Elephant\Future\Deferred;
use Elephant\Future\Future;
use Elephant\Future\Status;
use Elephant\Future\TimeoutException;
use Elephant\Loop\EventLoop;
use Fiber;
use Throwable;
use WeakMap;

/**
 * Work running in its own fiber, created by async().
 *
 * Cancelling a task that has not started yet keeps it from starting. Cancelling a task that is waiting
 * throws a CancelledException at the await where it waits, and cancels what it was waiting for.
 * A task cancelled while it runs, for example by itself, gets the CancelledException at its next await.
 *
 * @template-covariant T
 *
 * @implements Awaitable<T>
 */
final class Task implements Awaitable, Cancellable
{
    /**
     * Tasks by the fiber they run in.
     *
     * @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, Task<mixed>>|null
     */
    private static ?WeakMap $tasks = null;

    /**
     * @var Fiber<mixed, mixed, mixed, mixed>
     */
    private readonly Fiber $fiber;

    /**
     * @var Deferred<T>
     */
    private readonly Deferred $deferred;

    /**
     * What the task is currently waiting for, or null while it runs.
     *
     * @var Awaitable<mixed>|null
     */
    private ?Awaitable $awaiting = null;

    /**
     * Counts the waits, so a resume that belongs to an abandoned wait is ignored.
     */
    private int $ticket = 0;

    /**
     * Whether cancel() has already been called, so calling it again does nothing.
     */
    private bool $cancelled = false;

    /**
     * Whether a CancelledException still has to be thrown at the await where the task waits, or at its next one.
     */
    private bool $interrupted = false;

    /**
     * @param EventLoop $loop Loop that starts and resumes the task.
     * @param Closure(): T $work
     */
    public function __construct(
        private readonly EventLoop $loop,
        private readonly Closure $work,
    ) {
        $this->deferred = new Deferred($loop);
        $this->fiber = new Fiber($this->run(...));

        self::tasks()->offsetSet($this->fiber, $this);
    }

    /**
     * Returns the task that runs in the given fiber, or null when the fiber was not created by async().
     *
     * @param Fiber<mixed, mixed, mixed, mixed> $fiber
     *
     * @return Task<mixed>|null
     */
    public static function of(Fiber $fiber): ?self
    {
        if (self::tasks()->offsetExists($fiber)) {
            return self::tasks()->offsetGet($fiber);
        }

        return null;
    }

    /**
     * Starts the task on the next loop tick, unless it is cancelled before.
     *
     * @return $this
     */
    public function start(): self
    {
        $this->loop->defer(function (): void {
            if ($this->deferred->future()->isPending()) {
                $this->fiber->start();
            }
        });

        return $this;
    }

    /**
     * Stops the task; awaiting it afterwards throws a CancelledException.
     *
     * A task that has not started never starts, a waiting task stops at its await and cancels what it waits for,
     * a running task stops at its next await, and a finished task stays as it is.
     * Only the first call counts: a task that caught its cancellation is not interrupted again.
     */
    public function cancel(): void
    {
        if ($this->cancelled || $this->deferred->future()->isSettled()) {
            return;
        }

        $this->cancelled = true;

        if ($this->fiber->isStarted() === false) {
            $this->deferred->cancel();

            return;
        }

        $this->interrupted = true;

        if ($this->fiber->isSuspended()) {
            $this->interrupt();
        }
    }

    /**
     * Suspends the task until the awaitable settles and returns its value.
     *
     * @internal Called by await() inside the task's own fiber.
     *
     * @template V
     *
     * @param Awaitable<V> $awaitable
     *
     * @return V
     *
     * @throws CancelledException When the task is cancelled before or while it waits.
     * @throws Throwable The error the awaitable failed with.
     */
    public function wait(Awaitable $awaitable): mixed
    {
        $this->checkpoint();

        $future = $awaitable->future();
        $ticket = ++$this->ticket;
        $this->awaiting = $awaitable;

        $future->onSettle(function () use ($ticket): void {
            $this->wake($ticket);
        });

        Fiber::suspend();

        $this->checkpoint();

        return $future->result();
    }

    /**
     * Waits for the task and returns its value.
     *
     * @param float|null $timeout Seconds to wait at most; when they run out the task is cancelled.
     *
     * @return T
     *
     * @throws CancelledException When the task was cancelled.
     * @throws TimeoutException When the timeout runs out first.
     * @throws Throwable The error the task failed with.
     */
    public function await(?float $timeout = null): mixed
    {
        return await($this, $timeout);
    }

    /**
     * Returns where the task is in its lifecycle: pending, completed or failed.
     */
    public function status(): Status
    {
        return $this->deferred->future()->status();
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

    /**
     * Abandons the current wait, cancels what the task waits for and wakes the task, so its await throws.
     */
    private function interrupt(): void
    {
        $ticket = ++$this->ticket;
        $awaiting = $this->awaiting;
        $this->awaiting = null;

        if ($awaiting instanceof Cancellable) {
            $awaiting->cancel();
        }

        $this->loop->defer(function () use ($ticket): void {
            $this->wake($ticket);
        });
    }

    /**
     * Resumes the task, unless the wait the ticket belongs to was abandoned or the fiber is no longer suspended.
     */
    private function wake(int $ticket): void
    {
        if ($ticket === $this->ticket && $this->fiber->isSuspended()) {
            $this->awaiting = null;
            $this->fiber->resume();
        }
    }

    /**
     * Throws the pending cancellation, once.
     *
     * @throws CancelledException When the task was cancelled and the cancellation has not been thrown yet.
     */
    private function checkpoint(): void
    {
        if ($this->interrupted) {
            $this->interrupted = false;

            throw new CancelledException();
        }
    }

    /**
     * Runs the work inside the fiber and settles the task with its outcome.
     */
    private function run(): void
    {
        try {
            $this->deferred->complete(($this->work)());
        } catch (CancelledException) {
            $this->deferred->cancel();
        } catch (Throwable $error) {
            $this->deferred->fail($error);
        } finally {
            self::tasks()->offsetUnset($this->fiber);
        }
    }

    /**
     * @return WeakMap<Fiber<mixed, mixed, mixed, mixed>, Task<mixed>>
     */
    private static function tasks(): WeakMap
    {
        return self::$tasks ??= new WeakMap();
    }
}
