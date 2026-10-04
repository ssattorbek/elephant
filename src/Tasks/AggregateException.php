<?php
declare(strict_types=1);

namespace Elephant\Tasks;

use Elephant\ElephantException;
use Throwable;

/**
 * Thrown when every task given to Tasks::any() fails.
 */
final class AggregateException extends ElephantException
{
    /**
     * @param array<array-key, Throwable> $errors Errors keyed like the original tasks.
     */
    public function __construct(
        private readonly array $errors,
    ) {
        parent::__construct('All tasks failed.');
    }

    /**
     * Returns the error of every task, keyed like the original tasks.
     *
     * @return array<array-key, Throwable>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
