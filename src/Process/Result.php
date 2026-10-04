<?php
declare(strict_types=1);

namespace Elephant\Process;

/**
 * What a finished process left behind: its output, its error output and its exit code.
 */
final readonly class Result
{
    /**
     * Exit code of a process that finished without errors.
     */
    private const SUCCESS = 0;

    /**
     * @param string $output What the process wrote to its standard output.
     * @param string $errorOutput What the process wrote to its standard error.
     * @param int $exitCode Exit code the process finished with.
     */
    public function __construct(
        private string $output,
        private string $errorOutput,
        private int $exitCode,
    ) {
    }

    /**
     * Returns what the process wrote to its standard output.
     */
    public function output(): string
    {
        return $this->output;
    }

    /**
     * Returns what the process wrote to its standard error.
     */
    public function errorOutput(): string
    {
        return $this->errorOutput;
    }

    /**
     * Returns the exit code; zero means success.
     */
    public function exitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * Tells whether the process exited with code zero.
     */
    public function successful(): bool
    {
        return $this->exitCode === self::SUCCESS;
    }
}
