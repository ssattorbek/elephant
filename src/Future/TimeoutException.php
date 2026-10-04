<?php
declare(strict_types=1);

namespace Elephant\Future;

use Elephant\ElephantException;
use Symfony\Component\Console\Helper\Helper;

use function Symfony\Component\String\u as String;

/**
 * Thrown when awaiting takes longer than the timeout given to await().
 */
final class TimeoutException extends ElephantException
{
    /**
     * @param float $seconds The timeout that ran out.
     */
    public function __construct(float $seconds)
    {
        parent::__construct(String('Task timed out: ')->append(Helper::formatTime($seconds))->toString());
    }
}
