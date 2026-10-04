<?php
declare(strict_types=1);

namespace Elephant\Facades;

use Closure;
use Elephant\Application;
use Elephant\ElephantException;
use Elephant\Future\Awaitable;
use Elephant\Tasks\AggregateException;
use Elephant\Tasks\PendingResult;
use Elephant\Tasks\PendingResults;
use Elephant\Tasks\Result;

/**
 * Static entry point for running many tasks together.
 *
 * A task is either a closure, which is started with async(), or an awaitable that is already running.
 */
final class Tasks
{
    /**
     * Waits for every task and fails as soon as one of them fails, like Promise.all().
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, (Closure(): TValue)|Awaitable<TValue>> $tasks
     *
     * @return PendingResults<array<TKey, TValue>, TKey, TValue>
     */
    public static function all(array $tasks): PendingResults
    {
        return Application::tasks()->all($tasks);
    }

    /**
     * Runs the callback for every item as its own task and waits for all results, like Promise.all() over a map.
     *
     * The callback receives the item and, when it is user-defined and declares a second parameter, the item's key.
     * Built-in functions such as trim(...) only receive the item, so their optional parameters keep their defaults.
     * Combine it with limit() to cap how many items are processed at the same time.
     *
     * @template TKey of array-key
     * @template TItem
     * @template TValue
     *
     * @param array<TKey, TItem> $items
     * @param (Closure(TItem): TValue)|(Closure(TItem, TKey): TValue) $callback
     *
     * @return PendingResults<array<TKey, TValue>, TKey, TValue>
     */
    public static function map(array $items, Closure $callback): PendingResults
    {
        return Application::tasks()->map($items, $callback);
    }

    /**
     * Waits for every task and reports each outcome, like Promise.allSettled().
     *
     * @template TKey of array-key
     * @template TValue
     *
     * @param array<TKey, (Closure(): TValue)|Awaitable<TValue>> $tasks
     *
     * @return PendingResults<array<TKey, Result<TValue>>, TKey, Result<TValue>>
     */
    public static function settle(array $tasks): PendingResults
    {
        return Application::tasks()->settle($tasks);
    }

    /**
     * Settles like the first task to finish, like Promise.race().
     *
     * The other tasks are stopped once the race is decided, or when the race is cancelled or times out.
     *
     * @template TValue
     *
     * @param array<array-key, (Closure(): TValue)|Awaitable<TValue>> $tasks
     *
     * @return PendingResult<TValue>
     *
     * @throws ElephantException When no task is given.
     */
    public static function race(array $tasks): PendingResult
    {
        return Application::tasks()->race($tasks);
    }

    /**
     * Completes with the first successful task, like Promise.any().
     *
     * The result fails with an AggregateException when every task fails. The other tasks are stopped once a task
     * succeeds, or when the result is cancelled or times out.
     *
     * @template TValue
     *
     * @param array<array-key, (Closure(): TValue)|Awaitable<TValue>> $tasks
     *
     * @return PendingResult<TValue>
     *
     * @throws ElephantException When no task is given.
     *
     * @see AggregateException
     */
    public static function any(array $tasks): PendingResult
    {
        return Application::tasks()->any($tasks);
    }
}
