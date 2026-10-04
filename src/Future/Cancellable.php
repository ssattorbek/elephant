<?php
declare(strict_types=1);

namespace Elephant\Future;

/**
 * Work that can be stopped before it finishes.
 */
interface Cancellable
{
    /**
     * Stops the work; awaiting it afterwards throws a CancelledException.
     *
     * Cancelling work that has already finished does nothing.
     */
    public function cancel(): void;
}
