<?php
declare(strict_types=1);

namespace Elephant\Filesystem;

use Elephant\ElephantException;
use Throwable;

use function Symfony\Component\String\u as String;

/**
 * Thrown when reading, writing or deleting a file fails; a missing file raises the more specific FileNotFoundException.
 *
 * The underlying error, such as Symfony's IOException, is kept as the previous exception.
 */
class FilesystemException extends ElephantException
{
    /**
     * Creates the error for a file that could not be opened.
     */
    public static function cannotOpen(string $path, ?Throwable $previous = null): self
    {
        return new self(String('Cannot open file: ')->append($path)->toString(), $previous);
    }

    /**
     * Creates the error for a file that could not be read to the end.
     */
    public static function cannotRead(string $path): self
    {
        return new self(String('Cannot read file: ')->append($path)->toString());
    }

    /**
     * Creates the error for a file that could not be written completely.
     */
    public static function cannotWrite(string $path): self
    {
        return new self(String('Cannot write file: ')->append($path)->toString());
    }

    /**
     * Wraps an error raised by the underlying filesystem, keeping its message.
     */
    public static function because(Throwable $previous): self
    {
        return new self(String('Filesystem operation failed: ')->append($previous->getMessage())->toString(), $previous);
    }
}
