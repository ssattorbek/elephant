<?php
declare(strict_types=1);

namespace Elephant\Tasks;

use Elephant\Future\Future;
use Throwable;

/**
 * Outcome of a settled task: either a value or an error.
 *
 * @template-covariant T
 */
final class Result
{
    /**
     * @param T|null $value
     */
    private function __construct(
        private readonly mixed $value,
        private readonly ?Throwable $error,
    ) {
    }

    /**
     * Captures the outcome of a settled future.
     *
     * @template V
     *
     * @param Future<V> $future
     *
     * @return self<V>
     */
    public static function from(Future $future): self
    {
        if ($future->isFailed()) {
            return new self(null, $future->error());
        }

        return new self($future->result(), null);
    }

    /**
     * Whether the task produced a value.
     *
     * @phpstan-assert-if-true T $this->value()
     * @phpstan-assert-if-true null $this->error()
     * @phpstan-assert-if-false Throwable $this->error()
     * @phpstan-assert-if-false null $this->value()
     */
    public function successful(): bool
    {
        return $this->error === null;
    }

    /**
     * Whether the task failed with an error.
     *
     * @phpstan-assert-if-true Throwable $this->error()
     * @phpstan-assert-if-true null $this->value()
     * @phpstan-assert-if-false T $this->value()
     * @phpstan-assert-if-false null $this->error()
     */
    public function failed(): bool
    {
        return $this->error !== null;
    }

    /**
     * Returns the task's value, or null when it failed.
     *
     * @return T|null
     */
    public function value(): mixed
    {
        return $this->value;
    }

    /**
     * Returns the task's error, or null when it succeeded.
     */
    public function error(): ?Throwable
    {
        return $this->error;
    }
}
