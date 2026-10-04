<?php
declare(strict_types=1);

namespace Elephant\Tasks;

use ArrayAccess;
use ArrayIterator;
use Countable;
use Elephant\ElephantException;
use IteratorAggregate;

/**
 * Read-only results of a group of tasks, keyed and ordered like the original tasks.
 *
 * Reading a key that no task was given throws a TaskNotFoundException instead of returning null.
 *
 * TShape is the exact shape of the results, such as array{user: Response, count: int}; TKey and TValue
 * summarise it for editors that do not understand shapes.
 *
 * @template TShape of array<array-key, mixed>
 * @template TKey of array-key = key-of<TShape>
 * @template TValue = value-of<TShape>
 *
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 */
final class Results implements ArrayAccess, IteratorAggregate, Countable
{
    /**
     * @param TShape $results
     */
    public function __construct(
        private readonly array $results,
    ) {
    }

    /**
     * Whether a task with the given key exists.
     *
     * @param TKey $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->results);
    }

    /**
     * Returns the result of the task with the given key.
     *
     * @param TKey $offset
     *
     * @return TValue
     *
     * @phpstan-template TOffset of key-of<TShape>
     * @phpstan-param TOffset $offset
     * @phpstan-return TShape[TOffset]
     *
     * @throws TaskNotFoundException When no task has the given key.
     */
    public function offsetGet(mixed $offset): mixed
    {
        if (array_key_exists($offset, $this->results)) {
            return $this->results[$offset];
        }

        throw TaskNotFoundException::for($offset);
    }

    /**
     * @throws ElephantException Always, because results are read-only.
     */
    public function offsetSet(mixed $offset, mixed $value): never
    {
        throw new ElephantException('Task results are read-only.');
    }

    /**
     * @throws ElephantException Always, because results are read-only.
     */
    public function offsetUnset(mixed $offset): never
    {
        throw new ElephantException('Task results are read-only.');
    }

    /**
     * @return ArrayIterator<TKey, TValue>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->results);
    }

    /**
     * Returns how many tasks there were.
     */
    public function count(): int
    {
        return count($this->results);
    }

    /**
     * Returns the results as a plain array.
     *
     * @return TShape
     */
    public function all(): array
    {
        return $this->results;
    }
}
