<?php
declare(strict_types=1);

namespace Elephant\Tasks;

use Elephant\ElephantException;

use function Symfony\Component\String\u as String;

/**
 * Thrown when a result is read with a key that no task was given.
 */
final class TaskNotFoundException extends ElephantException
{
    /**
     * Creates the error for the missing key.
     */
    public static function for(int|string $key): self
    {
        return new self(String('Task not found: ')->append((string) $key)->toString());
    }
}
