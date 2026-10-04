<?php
declare(strict_types=1);

namespace Elephant\Channel;

use Elephant\ElephantException;
use Throwable;

/**
 * Thrown when sending on a closed channel, or receiving from a channel that is closed and empty.
 */
final class ChannelClosedException extends ElephantException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }
}
