<?php
declare(strict_types=1);

use Elephant\Facades\File;
use Elephant\Facades\Tasks;
use Elephant\Filesystem\FileNotFoundException;
use Elephant\Future\CancelledException;
use Elephant\Future\TimeoutException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Tests\Support\Journal;

use function Elephant\Flow\{async, await, delay};
use function Symfony\Component\String\u as String;

beforeEach(function (): void {
    $this->directory = Path::join(sys_get_temp_dir(), uniqid('elephant-file-', true));
    (new Filesystem())->mkdir($this->directory);
});

afterEach(function (): void {
    (new Filesystem())->remove($this->directory);
});

it('reads what it wrote', function (): void {
    $path = Path::join($this->directory, 'data.txt');

    File::write($path, 'hello elephant')->await();

    expect(File::read($path)->await())->toBe('hello elephant');
});

it('replaces the contents of an existing file', function (): void {
    $path = Path::join($this->directory, 'data.txt');

    File::write($path, 'first version')->await();
    File::write($path, 'second')->await();

    expect(File::read($path)->await())->toBe('second');
});

it('creates missing parent directories when writing', function (): void {
    $path = Path::join($this->directory, 'nested', 'deeper', 'out.txt');

    File::write($path, 'deep')->await();

    expect(File::read($path)->await())->toBe('deep');
});

it('writes an empty file', function (): void {
    $path = Path::join($this->directory, 'empty.txt');

    File::write($path, '')->await();

    expect(File::exists($path))->toBeTrue()
        ->and(File::read($path)->await())->toBe('');
});

it('leaves no temporary files behind after writing', function (): void {
    File::write(Path::join($this->directory, 'data.txt'), 'contents')->await();

    expect(scandir($this->directory))->toBe(['.', '..', 'data.txt']);
});

it('keeps the permissions of an existing file', function (): void {
    $path = Path::join($this->directory, 'data.txt');

    File::write($path, 'first version')->await();
    (new Filesystem())->chmod($path, 0o640);
    File::write($path, 'second')->await();

    expect((new SplFileInfo($path))->getPerms() & 0o777)->toBe(0o640);
});

it('gives a new file the default permissions', function (): void {
    $path = Path::join($this->directory, 'data.txt');
    $touched = Path::join($this->directory, 'touched.txt');

    File::write($path, 'hello elephant')->await();
    (new Filesystem())->touch($touched);

    expect((new SplFileInfo($path))->getPerms())->toBe((new SplFileInfo($touched))->getPerms());
});

it('appends to a file', function (): void {
    $path = Path::join($this->directory, 'log.txt');

    File::append($path, "first\n")->await();
    File::append($path, "second\n")->await();

    expect(File::read($path)->await())->toBe("first\nsecond\n");
});

it('reads lines without their line endings', function (): void {
    $path = Path::join($this->directory, 'lines.txt');

    File::write($path, "first\nsecond\r\nthird\n")->await();

    expect(File::lines($path)->await())->toBe(['first', 'second', 'third']);
});

it('keeps a last line without a line ending', function (): void {
    $path = Path::join($this->directory, 'lines.txt');

    File::write($path, "first\n\nlast")->await();

    expect(File::lines($path)->await())->toBe(['first', '', 'last']);
});

it('reads no lines from an empty file', function (): void {
    $path = Path::join($this->directory, 'empty.txt');

    File::write($path, '')->await();

    expect(File::lines($path)->await())->toBe([]);
});

it('tells whether a file exists', function (): void {
    $path = Path::join($this->directory, 'data.txt');

    expect(File::exists($path))->toBeFalse();

    File::write($path, 'here')->await();

    expect(File::exists($path))->toBeTrue();
});

it('deletes a file', function (): void {
    $path = Path::join($this->directory, 'data.txt');

    File::write($path, 'gone soon')->await();
    File::delete($path)->await();

    expect(File::exists($path))->toBeFalse();
});

it('deletes a missing file quietly', function (): void {
    expect(File::delete(Path::join($this->directory, 'missing.txt'))->await())->toBeNull();
});

it('throws when reading a missing file', function (): void {
    $path = Path::join($this->directory, 'missing.txt');

    expect(function () use ($path): void {
        File::read($path)->await();
    })->toThrow(FileNotFoundException::class, String('File not found: ')->append($path)->toString());
});

it('throws when reading lines of a missing file', function (): void {
    $path = Path::join($this->directory, 'missing.txt');

    expect(function () use ($path): void {
        File::lines($path)->await();
    })->toThrow(FileNotFoundException::class, String('File not found: ')->append($path)->toString());
});

it('reads a large file while other tasks keep running', function (): void {
    $path = Path::join($this->directory, 'large.bin');
    $contents = random_bytes(4 * 1024 * 1024);
    file_put_contents($path, $contents);
    $journal = new Journal();

    $ticker = async(static function () use ($journal): void {
        for ($tick = 1; $tick <= 5; $tick++) {
            $journal->write('tick');
            await(delay(0));
        }
    });

    $reader = async(static function () use ($path, $journal): string {
        $text = File::read($path)->await();
        $journal->write('read');

        return $text;
    });

    $text = $reader->await();
    $ticker->await();

    expect(strlen($text))->toBe(strlen($contents))
        ->and(md5($text))->toBe(md5($contents))
        ->and($journal->entries())->toBe(['tick', 'tick', 'tick', 'tick', 'tick', 'read']);
});

it('writes a large file correctly', function (): void {
    $path = Path::join($this->directory, 'large.bin');
    $contents = random_bytes(3 * 1024 * 1024 + 123);

    File::write($path, $contents)->await();

    expect(md5_file($path))->toBe(md5($contents));
});

it('leaves no target file when a write is cancelled', function (): void {
    $path = Path::join($this->directory, 'large.bin');

    $write = File::write($path, random_bytes(4 * 1024 * 1024));

    await(delay(0));
    $write->cancel();

    expect(function () use ($write): void {
        $write->await();
    })->toThrow(CancelledException::class)
        ->and(File::exists($path))->toBeFalse()
        ->and(scandir($this->directory))->toBe(['.', '..']);
});

it('keeps the old contents when a write is cancelled', function (): void {
    $path = Path::join($this->directory, 'data.txt');
    File::write($path, 'original')->await();

    $write = File::write($path, random_bytes(4 * 1024 * 1024));

    await(delay(0));
    $write->cancel();

    expect(function () use ($write): void {
        $write->await();
    })->toThrow(CancelledException::class)
        ->and(File::read($path)->await())->toBe('original')
        ->and(scandir($this->directory))->toBe(['.', '..', 'data.txt']);
});

it('finishes within a timeout', function (): void {
    $path = Path::join($this->directory, 'data.txt');

    File::write($path, 'quick')->await(timeout: 1);

    expect(File::read($path)->await(timeout: 1))->toBe('quick');
});

it('cancels a write when the timeout runs out', function (): void {
    $path = Path::join($this->directory, 'huge.bin');

    expect(function () use ($path): void {
        File::write($path, random_bytes(16 * 1024 * 1024))->await(timeout: 0.001);
    })->toThrow(TimeoutException::class);

    await(delay(0.01));

    expect(File::exists($path))->toBeFalse();
});

it('combines file tasks with other tasks', function (): void {
    $first = Path::join($this->directory, 'first.txt');
    $second = Path::join($this->directory, 'second.txt');
    File::write($first, 'one')->await();
    File::write($second, 'two')->await();

    $results = Tasks::all(['first' => File::read($first), 'second' => File::read($second)])->await();

    expect($results->all())->toBe(['first' => 'one', 'second' => 'two']);
});
