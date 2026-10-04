<?php
declare(strict_types=1);

namespace Elephant\Process;

use Elephant\Flow\Task;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

use function Elephant\Flow\{async, await, delay};

/**
 * Runs external commands on top of Symfony Process without blocking the event loop.
 *
 * The process runs in the background while other tasks keep going; its task checks on it every few
 * milliseconds and finishes with the result once the process exits. Cancelling the task stops the process.
 */
final class Runner
{
    /**
     * Seconds between two checks on a running process.
     */
    private const INTERVAL = 0.01;

    /**
     * Seconds a cancelled process gets to exit before it is killed.
     */
    private const GRACE = 0.0;

    /**
     * Runs the command and resolves with its result once it exits.
     *
     * An array runs the program directly with its arguments; a string runs through the shell, so pipes and redirects work.
     * The task fails with a ProcessException when the process cannot be started.
     *
     * @param list<string>|string $command
     *
     * @return Task<Result>
     */
    public function run(array|string $command, ?string $directory = null): Task
    {
        return async(static function () use ($command, $directory): Result {
            $process = self::create($command, $directory);

            try {
                $process->start();
            } catch (ExceptionInterface $error) {
                throw ProcessException::because($error);
            }

            try {
                while ($process->isRunning()) {
                    await(delay(self::INTERVAL));
                }
            } finally {
                $process->stop(self::GRACE);
            }

            return new Result($process->getOutput(), $process->getErrorOutput(), (int) $process->getExitCode());
        });
    }

    /**
     * Creates the Symfony process, without its own timeout; limit the task with ->await(timeout: ...) instead.
     *
     * @param list<string>|string $command
     */
    private static function create(array|string $command, ?string $directory): Process
    {
        if (is_string($command)) {
            return Process::fromShellCommandline($command, $directory, timeout: null);
        }

        return new Process($command, $directory, timeout: null);
    }
}
