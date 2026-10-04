<?php
declare(strict_types=1);

namespace Elephant\Future;

use Elephant\ElephantException;

/**
 * Thrown when awaiting a task that was cancelled before it finished.
 */
final class CancelledException extends ElephantException
{
    public function __construct()
    {
        parent::__construct('The task was cancelled.');
    }
}
