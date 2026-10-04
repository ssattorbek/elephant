<?php
declare(strict_types=1);

namespace Elephant\Http;

use Elephant\ElephantException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Thrown when a request cannot be completed at the network level, such as DNS failures, refused connections or timeouts.
 */
final class ConnectionException extends ElephantException
{
    /**
     * Wraps a Symfony transport error, keeping it as the previous exception.
     */
    public static function from(TransportExceptionInterface $error): self
    {
        return new self($error->getMessage(), $error);
    }
}
