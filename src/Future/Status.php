<?php
declare(strict_types=1);

namespace Elephant\Future;

/**
 * Lifecycle state of a future.
 */
enum Status
{
    /**
     * The value is not available yet.
     */
    case Pending;

    /**
     * The future settled with a value.
     */
    case Completed;

    /**
     * The future settled with an error.
     */
    case Failed;
}
