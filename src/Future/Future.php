<?php
declare(strict_types=1);

namespace Elephant\Future;

use Closure;
use Elephant\ElephantException;
use Elephant\Future\Internal\State;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Throwable;

use function Elephant\Flow\async;
use function Elephant\Flow\await;

/**
 * Read-only view of a value that becomes available later.
 *
 * @template-covariant T
 *
 * @implements Awaitable<T>
 */
final class Future implements Awaitable
{
    /**
     * @param State<T> $state
     */
    public function __construct(
        private readonly State $state,
    ) {
    }

    /**
     * Returns where the future is in its lifecycle: pending, completed or failed.
     */
    public function status(): Status
    {
        return $this->state->status();
    }

    /**
     * Whether the future is still waiting for its value.
     */
    public function isPending(): bool
    {
        return $this->state->status() === Status::Pending;
    }

    /**
     * Whether the future has completed or failed.
     */
    public function isSettled(): bool
    {
        return $this->state->status() !== Status::Pending;
    }

    /**
     * Whether the future settled with a value.
     */
    public function isCompleted(): bool
    {
        return $this->state->status() === Status::Completed;
    }

    /**
     * Whether the future settled with an error.
     */
    public function isFailed(): bool
    {
        return $this->state->status() === Status::Failed;
    }

    /**
     * Registers a callback that runs on the event loop once the future settles.
     *
     * @param Closure(): void $callback
     */
    public function onSettle(Closure $callback): void
    {
        $this->state->subscribe($callback);
    }

    /**
     * Returns the settled value without waiting.
     *
     * @return T
     *
     * @throws Throwable The error the future failed with.
     * @throws ElephantException When the future is still pending.
     */
    public function result(): mixed
    {
        return $this->state->result();
    }

    /**
     * Returns the error the future failed with.
     *
     * @throws ElephantException When the future has not failed.
     */
    public function error(): Throwable
    {
        return $this->state->error();
    }

    /**
     * Waits for the future and returns its value.
     *
     * @param float|null $timeout Seconds to wait at most.
     *
     * @return T
     *
     * @throws TimeoutException When the timeout runs out first.
     * @throws Throwable The error the future failed with.
     */
    public function await(?float $timeout = null): mixed
    {
        return await($this, $timeout);
    }

    /**
     * @return Future<T>
     */
    public function future(): Future
    {
        return $this;
    }

    /**
     * Recovers from errors whose type matches the handler's first parameter.
     *
     * An untyped handler catches every error. Errors the handler does not accept are passed on unchanged,
     * so several handlers can be chained for different error types.
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
        return async(function () use ($handler): mixed {
            try {
                return await($this);
            } catch (Throwable $error) {
                if (self::catches($handler, $error)) {
                    return $handler($error);
                }

                throw $error;
            }
        })->future();
    }

    /**
     * Checks whether the handler's first parameter type accepts the error.
     *
     * @template E of Throwable
     *
     * @param Closure(E): mixed $handler
     *
     * @phpstan-assert-if-true E $error
     */
    private static function catches(Closure $handler, Throwable $error): bool
    {
        $parameter = PropertyAccess::createPropertyAccessor()->getValue((new ReflectionFunction($handler))->getParameters(), '[0]');
        $type = $parameter?->getType();

        $candidates = match (true) {
            $type instanceof ReflectionUnionType => $type->getTypes(),
            $type instanceof ReflectionNamedType => [$type],
            default => [],
        };

        if ($candidates === []) {
            return true;
        }

        foreach ($candidates as $candidate) {
            if ($candidate instanceof ReflectionNamedType && is_a($error, $candidate->getName())) {
                return true;
            }
        }

        return false;
    }
}
