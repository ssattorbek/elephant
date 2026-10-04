<?php
declare(strict_types=1);

namespace Elephant\Facades;

use Elephant\Filesystem\FileNotFoundException;
use Elephant\Filesystem\Filesystem;
use Elephant\Filesystem\FilesystemException;
use Elephant\Flow\Task;

/**
 * Static entry point for cooperative file access.
 *
 * Files are processed in chunks and every operation yields to the event loop between two chunks,
 * so other tasks keep running. Every operation returns a task that can be awaited, cancelled,
 * combined with Tasks::all() and limited with ->await(timeout: ...).
 *
 * @see FileNotFoundException
 * @see FilesystemException
 */
final class File
{
    /**
     * Reads the whole file; the task fails with a FileNotFoundException when it does not exist.
     *
     * @return Task<string>
     */
    public static function read(string $path): Task
    {
        return self::filesystem()->read($path);
    }

    /**
     * Replaces the file atomically, creating missing parent directories.
     *
     * A cancelled write leaves the target untouched.
     *
     * @return Task<null>
     */
    public static function write(string $path, string $contents): Task
    {
        return self::filesystem()->write($path, $contents);
    }

    /**
     * Adds the contents to the end of the file, creating it when it is missing.
     *
     * @return Task<null>
     */
    public static function append(string $path, string $contents): Task
    {
        return self::filesystem()->append($path, $contents);
    }

    /**
     * Reads the file as lines without their line endings; the task fails with a FileNotFoundException when it does not exist.
     *
     * @return Task<list<string>>
     */
    public static function lines(string $path): Task
    {
        return self::filesystem()->lines($path);
    }

    /**
     * Tells whether the file or directory exists, right away.
     */
    public static function exists(string $path): bool
    {
        return self::filesystem()->exists($path);
    }

    /**
     * Removes the file; a missing file is not an error.
     *
     * @return Task<null>
     */
    public static function delete(string $path): Task
    {
        return self::filesystem()->delete($path);
    }

    /**
     * Creates the filesystem the facade delegates to.
     */
    private static function filesystem(): Filesystem
    {
        return new Filesystem();
    }
}
