<?php
declare(strict_types=1);

namespace Elephant\Flow;

use Closure;
use Elephant\Application;
use Elephant\ElephantException;
use Elephant\Future\Awaitable;
use Elephant\Future\TimeoutException;
use Fiber;
use Throwable;

/**
 * Runs a task in its own fiber on the event loop.
 *
 * The task starts on the next loop tick, so the caller continues immediately.
 * It can be cancelled until it finishes; a waiting task stops at the await where it waits.
 *
 * @template T
 *
 * @param Closure(): T $task
 *
 * @return Task<T>
 */
function async(Closure $task): Task
{
    return (new Task(Application::loop(), $task))->start();
}

/**
 * Waits for an awaitable and returns its value.
 *
 * Inside a fiber only that fiber is suspended, so other tasks keep running.
 * Outside a fiber the event loop runs until the value is available.
 *
 * @template T
 *
 * @param Awaitable<T> $awaitable
 * @param float|null $timeout Seconds to wait at most; when they run out the work is cancelled.
 *
 * @return T
 *
 * @throws Throwable The error the awaitable failed with.
 * @throws TimeoutException When the timeout runs out first.
 * @throws ElephantException When the awaitable can never settle or the timeout is not positive.
 */
function await(Awaitable $awaitable, ?float $timeout = null): mixed
{
    if ($timeout !== null) {
        if ($timeout <= 0) {
            throw new ElephantException('The timeout must be longer than zero seconds.');
        }

        return await(new Deadline(Application::loop(), $awaitable, $timeout, new TimeoutException($timeout)));
    }

    $future = $awaitable->future();
    $fiber = Fiber::getCurrent();

    if ($fiber === null) {
        Application::loop()->runUntil(static fn (): bool => $future->isSettled());

        return $future->result();
    }

    $task = Task::of($fiber);

    if ($task !== null) {
        return $task->wait($awaitable);
    }

    $future->onSettle(static function () use ($fiber): void {
        if ($fiber->isSuspended()) {
            $fiber->resume();
        }
    });

    Fiber::suspend();

    return $future->result();
}

/**
 * Returns a pause that completes after the given number of seconds.
 *
 * The pause can be cancelled: cancelling it, cancelling a task that awaits it, or a timeout running out
 * removes its timer, so it no longer keeps the script running.
 */
function delay(float $seconds): Delay
{
    return new Delay(Application::loop(), $seconds);
}

/**
 * Runs the callback every given number of seconds, until it is cancelled or has run the given number of times.
 *
 * The first run happens after one interval, and the next interval starts only after the previous run finished.
 * Without a limit the interval keeps the script running until it is cancelled.
 *
 * @param Closure(): mixed $callback Work to repeat; it runs in its own task, so it may await.
 * @param int|null $times How many times to run, or null to run until cancelled.
 *
 * @throws ElephantException When the interval is not positive or the limit is lower than one.
 */
function every(float $seconds, Closure $callback, ?int $times = null): Interval
{
    if ($seconds <= 0) {
        throw new ElephantException('The interval must be longer than zero seconds.');
    }

    if ($times !== null && $times < 1) {
        throw new ElephantException('An interval must run at least once.');
    }

    return (new Interval(Application::loop(), $seconds, $callback, $times))->start();
}
