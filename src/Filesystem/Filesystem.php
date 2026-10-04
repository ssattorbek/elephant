<?php
declare(strict_types=1);

namespace Elephant\Filesystem;

use Elephant\Flow\Task;
use LogicException;
use RuntimeException;
use SplFileInfo;
use SplFileObject;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem as Disk;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\String\ByteString;

use function Elephant\Flow\{async, await, delay};
use function Symfony\Component\String\b as Bytes;
use function Symfony\Component\String\u as String;

/**
 * Cooperative file access on top of Symfony Filesystem.
 *
 * PHP has no non-blocking disk I/O, so files are read and written in chunks and every operation
 * yields to the event loop between two chunks; other tasks keep running while a large file is processed.
 * Every operation runs in its own task, so it can be awaited, cancelled, combined and limited with a timeout.
 */
final class Filesystem
{
    /**
     * Number of bytes read or written before yielding to the event loop.
     */
    public const CHUNK_SIZE = 65536;

    /**
     * Name prefix of the temporary files that writes go through before they replace the target.
     */
    private const TEMPORARY = 'elephant';

    /**
     * Number of random characters that make the name of a temporary file unique.
     */
    private const RANDOM = 16;

    /**
     * Ends every line; a carriage return before it belongs to the line ending as well.
     */
    private const NEWLINE = "\n";

    /**
     * Precedes the newline in Windows line endings.
     */
    private const CARRIAGE_RETURN = "\r";

    /**
     * @param Disk $disk Symfony Filesystem that checks, creates, moves and removes files.
     */
    public function __construct(
        private readonly Disk $disk = new Disk(),
    ) {
    }

    /**
     * Reads the whole file.
     *
     * The task fails with a FileNotFoundException when the file does not exist,
     * and with a FilesystemException when it cannot be read.
     *
     * @return Task<string>
     */
    public function read(string $path): Task
    {
        return async(function () use ($path): string {
            return $this->contents($path);
        });
    }

    /**
     * Replaces the file with the given contents, creating missing parent directories.
     *
     * The contents go to a temporary file next to the target, which is renamed over the target at the end,
     * so readers never see a half-written file and a cancelled write leaves the target untouched.
     * An existing target keeps its permissions; a new one gets the default permissions of the process.
     *
     * @return Task<null>
     */
    public function write(string $path, string $contents): Task
    {
        return async(function () use ($path, $contents): mixed {
            $this->replace($path, $contents);

            return null;
        });
    }

    /**
     * Adds the contents to the end of the file, creating the file and missing parent directories.
     *
     * Appending writes to the file itself, so a cancelled append may leave part of the contents behind.
     *
     * @return Task<null>
     */
    public function append(string $path, string $contents): Task
    {
        return async(function () use ($path, $contents): mixed {
            $this->directory($path);
            $this->stream($path, 'ab', $contents);

            return null;
        });
    }

    /**
     * Reads the file as lines, without their line endings.
     *
     * Lines may end with "\n" or "\r\n"; a line ending at the end of the file does not add an empty line.
     *
     * @return Task<list<string>>
     */
    public function lines(string $path): Task
    {
        return async(function () use ($path): array {
            return self::split($this->contents($path));
        });
    }

    /**
     * Tells whether the file or directory exists, right away.
     */
    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }

    /**
     * Removes the file; a missing file is not an error, and a directory is removed with everything inside.
     *
     * @return Task<null>
     */
    public function delete(string $path): Task
    {
        return async(function () use ($path): mixed {
            try {
                $this->disk->remove($path);
            } catch (IOExceptionInterface $error) {
                throw FilesystemException::because($error);
            }

            return null;
        });
    }

    /**
     * Reads the file chunk by chunk, yielding to the event loop after every chunk.
     *
     * @throws FileNotFoundException When the file does not exist.
     * @throws FilesystemException When the file cannot be opened or read.
     */
    private function contents(string $path): string
    {
        if ($this->disk->exists($path) === false) {
            throw FileNotFoundException::for($path);
        }

        $file = $this->open($path, 'rb');
        $chunks = [];

        while ($file->eof() === false) {
            $chunk = $file->fread(self::CHUNK_SIZE);

            if ($chunk === false) {
                throw FilesystemException::cannotRead($path);
            }

            $chunks[] = $chunk;
            await(delay(0));
        }

        return Bytes()->join($chunks)->toString();
    }

    /**
     * Writes the contents to a temporary file and moves it over the target.
     *
     * @throws FilesystemException When a directory, the temporary file or the target cannot be written.
     */
    private function replace(string $path, string $contents): void
    {
        $name = String(self::TEMPORARY)->append(ByteString::fromRandom(self::RANDOM)->toString());
        $temporary = Path::join($this->directory($path), $name->toString());

        try {
            $this->stream($temporary, 'xb', $contents);
            $this->inherit($temporary, $path);
            $this->disk->rename($temporary, $path, true);
        } catch (IOExceptionInterface $error) {
            throw FilesystemException::because($error);
        } finally {
            $this->discard($temporary);
        }
    }

    /**
     * Writes the contents chunk by chunk, yielding to the event loop after every chunk.
     *
     * @param 'xb'|'ab' $mode
     *
     * @throws FilesystemException When the file cannot be opened or written completely.
     */
    private function stream(string $path, string $mode, string $contents): void
    {
        $file = $this->open($path, $mode);

        foreach (Bytes($contents)->chunk(self::CHUNK_SIZE) as $chunk) {
            if ($file->fwrite($chunk->toString()) !== $chunk->length()) {
                throw FilesystemException::cannotWrite($path);
            }

            await(delay(0));
        }

        if ($file->fflush() === false) {
            throw FilesystemException::cannotWrite($path);
        }
    }

    /**
     * Opens the file, turning PHP's errors into a FilesystemException.
     *
     * @throws FilesystemException When the file cannot be opened.
     */
    private function open(string $path, string $mode): SplFileObject
    {
        try {
            return new SplFileObject($path, $mode);
        } catch (RuntimeException|LogicException $error) {
            throw FilesystemException::cannotOpen($path, $error);
        }
    }

    /**
     * Creates the parent directory of the path when it is missing and returns it as an absolute path.
     *
     * @throws FilesystemException When the directory cannot be created.
     */
    private function directory(string $path): string
    {
        $directory = Path::getDirectory(Path::makeAbsolute($path, (string) getcwd()));

        try {
            $this->disk->mkdir($directory);
        } catch (IOExceptionInterface $error) {
            throw FilesystemException::because($error);
        }

        return $directory;
    }

    /**
     * Gives the temporary file the permissions of the existing target; a new file keeps the default permissions it was created with.
     *
     * @throws IOExceptionInterface When the permissions cannot be changed.
     */
    private function inherit(string $temporary, string $path): void
    {
        if ($this->disk->exists($path)) {
            $this->disk->chmod($temporary, (new SplFileInfo($path))->getPerms());
        }
    }

    /**
     * Removes the temporary file when it is still there.
     *
     * @throws FilesystemException When the temporary file cannot be removed.
     */
    private function discard(string $temporary): void
    {
        try {
            $this->disk->remove($temporary);
        } catch (IOExceptionInterface $error) {
            throw FilesystemException::because($error);
        }
    }

    /**
     * Splits the contents into lines without their line endings; a line ending at the very end adds no empty line.
     *
     * @return list<string>
     */
    private static function split(string $contents): array
    {
        $text = Bytes($contents);

        if ($text->isEmpty()) {
            return [];
        }

        $lines = $text->trimSuffix(self::NEWLINE)->split(self::NEWLINE);

        return array_values(array_map(static fn (ByteString $line): string => $line->trimSuffix(self::CARRIAGE_RETURN)->toString(), $lines));
    }
}
