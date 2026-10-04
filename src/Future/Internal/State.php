<?php
declare(strict_types=1);

namespace Elephant\Future\Internal;

use Closure;
use Elephant\ElephantException;
use Elephant\Future\CancelledException;
use Elephant\Future\Status;
use Elephant\Loop\EventLoop;
use Throwable;

/**
 * Shared settlement state behind a Future and its Deferred.
 *
 * Errors that nobody observes are rethrown from the event loop, so failures never disappear silently.
 *
 * @internal
 *
 * @template T
 */
final class State
{
    private Status $status = Status::Pending;

    /**
     * @var T
     */
    private mixed $value;

    private Throwable $error;

    /**
     * Whether anyone has observed the outcome of this state.
     */
    private bool $handled = false;

    /**
     * @var list<Closure(): void>
     */
    private array $callbacks = [];

    /**
     * @param EventLoop $loop Loop used to schedule settlement callbacks.
     */
    public function __construct(
        private readonly EventLoop $loop,
    ) {
    }

    /**
     * Returns the current lifecycle state.
     */
    public function status(): Status
    {
        return $this->status;
    }

    /**
     * Settles the state with a value.
     *
     * @param T $value
     *
     * @throws ElephantException When the state is already settled.
     */
    public function complete(mixed $value): void
    {
        $this->assertPending();

        $this->status = Status::Completed;
        $this->value = $value;

        $this->notify();
    }

    /**
     * Settles the state with an error.
     *
     * @throws ElephantException When the state is already settled.
     */
    public function fail(Throwable $error): void
    {
        $this->assertPending();

        $this->status = Status::Failed;
        $this->error = $error;

        $this->notify();

        $this->loop->defer(function () use ($error): void {
            if ($this->handled === false) {
                throw $error;
            }
        });
    }

    /**
     * Fails the state with a CancelledException, unless it has already settled.
     *
     * A cancellation counts as observed, so it is never rethrown from the event loop when nobody awaits it.
     */
    public function cancel(): void
    {
        if ($this->status !== Status::Pending) {
            return;
        }

        $this->status = Status::Failed;
        $this->error = new CancelledException();
        $this->handled = true;

        $this->notify();
    }

    /**
     * Registers a callback that runs on the event loop once the state settles.
     *
     * @param Closure(): void $callback
     */
    public function subscribe(Closure $callback): void
    {
        $this->handled = true;

        if ($this->status === Status::Pending) {
            $this->callbacks[] = $callback;

            return;
        }

        $this->loop->defer($callback);
    }

    /**
     * Returns the settled value or rethrows the settled error.
     *
     * @return T
     *
     * @throws Throwable The error the state failed with.
     * @throws ElephantException When the state is still pending.
     */
    public function result(): mixed
    {
        $this->handled = true;

        return match ($this->status) {
            Status::Completed => $this->value,
            Status::Failed => throw $this->error,
            Status::Pending => throw new ElephantException('The future is still pending.'),
        };
    }

    /**
     * Returns the error the state failed with.
     *
     * @throws ElephantException When the state has not failed.
     */
    public function error(): Throwable
    {
        $this->handled = true;

        if ($this->status === Status::Failed) {
            return $this->error;
        }

        throw new ElephantException('The future has not failed.');
    }

    /**
     * @throws ElephantException When the state is already settled.
     */
    private function assertPending(): void
    {
        if ($this->status !== Status::Pending) {
            throw new ElephantException('The future is already settled.');
        }
    }

    /**
     * Schedules every waiting callback on the event loop.
     */
    private function notify(): void
    {
        foreach ($this->callbacks as $callback) {
            $this->loop->defer($callback);
        }

        $this->callbacks = [];
    }
}
