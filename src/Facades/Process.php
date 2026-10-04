<?php
declare(strict_types=1);

namespace Elephant\Facades;

use Elephant\Flow\Task;
use Elephant\Process\ProcessException;
use Elephant\Process\Result;
use Elephant\Process\Runner;

/**
 * Static entry point for running external commands without blocking the event loop.
 *
 * Every command returns a task that can be awaited, cancelled, combined with Tasks::all()
 * and limited with ->await(timeout: ...); a cancelled or timed out task stops its process.
 *
 * @see ProcessException
 */
final class Process
{
    /**
     * Runs the command and resolves with its output and exit code once it exits.
     *
     * An array runs the program directly with its arguments; a string runs through the shell, so pipes and redirects work.
     *
     * @param list<string>|string $command
     *
     * @return Task<Result>
     */
    public static function run(array|string $command, ?string $directory = null): Task
    {
        return self::runner()->run($command, $directory);
    }

    /**
     * Returns a new runner on top of Symfony Process.
     */
    private static function runner(): Runner
    {
        return new Runner();
    }
}
