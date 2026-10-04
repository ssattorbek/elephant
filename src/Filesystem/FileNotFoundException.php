<?php
declare(strict_types=1);

namespace Elephant\Filesystem;

use function Symfony\Component\String\u as String;

/**
 * Thrown when a file that should be read does not exist.
 */
final class FileNotFoundException extends FilesystemException
{
    /**
     * Creates the error for the missing path.
     */
    public static function for(string $path): self
    {
        return new self(String('File not found: ')->append($path)->toString());
    }
}
