<?php
declare(strict_types=1);

namespace Elephant\Future;

use Elephant\ElephantException;
use Elephant\Future\Internal\State;
use Elephant\Loop\EventLoop;
use Throwable;

/**
 * Producer side of a future: whoever holds it decides how the future settles.
 *
 * @template T
 */
final class Deferred
{
    /**
     * @var State<T>
     */
    private readonly State $state;

    /**
     * @var Future<T>
     */
    private readonly Future $future;

    /**
     * @param EventLoop $loop Loop used to notify the future's subscribers.
     */
    public function __construct(EventLoop $loop)
    {
        $this->state = new State($loop);
        $this->future = new Future($this->state);
    }

    /**
     * Returns the consumer side of this deferred.
     *
     * @return Future<T>
     */
    public function future(): Future
    {
        return $this->future;
    }

    /**
     * Settles the future with a value.
     *
     * @param T $value
     *
     * @throws ElephantException When the future is already settled.
     */
    public function complete(mixed $value = null): void
    {
        $this->state->complete($value);
    }

    /**
     * Settles the future with an error.
     *
     * @throws ElephantException When the future is already settled.
     */
    public function fail(Throwable $error): void
    {
        $this->state->fail($error);
    }

    /**
     * Fails the future with a CancelledException, unless it has already settled.
     */
    public function cancel(): void
    {
        $this->state->cancel();
    }
}
