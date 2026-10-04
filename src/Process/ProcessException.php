<?php
declare(strict_types=1);

namespace Elephant\Process;

use Elephant\ElephantException;
use Throwable;

use function Symfony\Component\String\u as String;

/**
 * Thrown when a process cannot be started, for example because its working directory does not exist.
 *
 * A process that starts and then exits with a non-zero code is not an error; check Result::successful() instead.
 */
final class ProcessException extends ElephantException
{
    /**
     * Wraps the error Symfony Process raised, keeping its message.
     */
    public static function because(Throwable $previous): self
    {
        return new self(String('Cannot start process: ')->append($previous->getMessage())->toString(), $previous);
    }
}
