<?php
declare(strict_types=1);

namespace Elephant;

use RuntimeException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Throwable;

use function Symfony\Component\String\u as String;

/**
 * Base class of every error raised by Elephant.
 *
 * The error points at the line of your code that led to it, not at the library internals,
 * and it is rendered as an Elephant error block even when it is thrown before the event loop starts.
 */
class ElephantException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        Application::guard();

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $call = new ParameterBag($frame);

            if ($call->has('file') && String($call->getString('file'))->startsWith(__DIR__) === false) {
                $this->file = $call->getString('file');
                $this->line = $call->getInt('line');

                return;
            }
        }
    }
}
